<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

use DreamFactory\Core\AI\Providers\Streaming\SseFrameParser;
use RuntimeException;

/**
 * Generic OpenAI-compatible provider.
 *
 * Works with any API that implements the OpenAI Chat Completions spec:
 *   POST /v1/chat/completions
 *   GET  /v1/models
 *   POST /v1/embeddings
 *
 * Extended by OpenAIProvider, XaiProvider, and used directly for custom endpoints.
 */
class OpenAICompatibleProvider extends BaseAiProvider
{
    protected function buildAuthHeaders(?string $organizationId): array
    {
        $headers = [];
        if ($this->apiKey) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        if ($organizationId) {
            $headers['OpenAI-Organization'] = $organizationId;
        }
        return $headers;
    }

    public function complete(array $options): array
    {
        $messages = [];
        if ($this->systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $this->systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $options['prompt'] ?? ''];
        return $this->chat($messages, $options);
    }

    public function chat(array $messages, array $options = []): array
    {
        $model = $this->resolveModel($options);
        $maxTokens = $this->resolveMaxTokens($options);
        $temperature = $this->resolveTemperature($options);

        // Prepend service-level system prompt if no system message is already present.
        if ($this->systemPrompt && !$this->hasSystemMessage($messages)) {
            array_unshift($messages, ['role' => 'system', 'content' => $this->systemPrompt]);
        }

        $payload = array_merge([
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages'   => $messages,
        ], $this->extraParams);

        $body = $this->request('POST', '/v1/chat/completions', ['json' => $payload]);

        if (!isset($body['choices'][0]['message']['content'])) {
            throw new RuntimeException(
                $this->getProviderName() . ' API returned unexpected response: '
                . substr((string) json_encode($body), 0, 300)
            );
        }

        $choice = $body['choices'][0];

        return [
            'content'       => $choice['message']['content'],
            'provider'      => $this->getProviderName(),
            'model'         => $body['model'] ?? $model,
            'input_tokens'  => $body['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $body['usage']['completion_tokens'] ?? 0,
            'finish_reason' => $choice['finish_reason'] ?? 'unknown',
        ];
    }

    public function chatWithTools(array $messages, array $tools, array $options = []): array
    {
        $model = $this->resolveModel($options);
        $maxTokens = $this->resolveMaxTokens($options);
        $temperature = $this->resolveTemperature($options);

        if ($this->systemPrompt && !$this->hasSystemMessage($messages)) {
            array_unshift($messages, ['role' => 'system', 'content' => $this->systemPrompt]);
        }

        $payload = array_merge([
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'messages'    => $messages,
            'tools'       => ToolDefinition::toOpenAIArray($tools),
        ], $this->extraParams);

        $body = $this->request('POST', '/v1/chat/completions', ['json' => $payload]);

        if (!isset($body['choices'][0]['message'])) {
            throw new RuntimeException(
                $this->getProviderName() . ' API returned unexpected response: '
                . substr((string) json_encode($body), 0, 300)
            );
        }

        $message = $body['choices'][0]['message'];
        $finishReason = $body['choices'][0]['finish_reason'] ?? 'unknown';

        $toolCalls = null;
        if (!empty($message['tool_calls'])) {
            $toolCalls = array_map(
                fn(array $tc) => ToolCall::fromOpenAI($tc)->toArray(),
                $message['tool_calls'],
            );
        }

        return [
            'content'       => $message['content'] ?? null,
            'tool_calls'    => $toolCalls,
            'provider'      => $this->getProviderName(),
            'model'         => $body['model'] ?? $model,
            'input_tokens'  => $body['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $body['usage']['completion_tokens'] ?? 0,
            'finish_reason' => $finishReason,
        ];
    }

    public function listModels(): array
    {
        $body = $this->request('GET', '/v1/models');
        $models = [];
        foreach ($body['data'] ?? [] as $m) {
            $models[] = [
                'id'             => $m['id'] ?? '',
                'name'           => $m['id'] ?? '',
                'context_window' => $m['context_window'] ?? null,
            ];
        }
        return $models;
    }

    public function embeddings(string|array $input, array $options = []): array
    {
        $model = $options['model'] ?? $this->defaultModel;
        $payload = [
            'model' => $model,
            'input' => is_array($input) ? $input : [$input],
        ];

        $body = $this->request('POST', '/v1/embeddings', ['json' => $payload]);

        return [
            'data'  => $body['data'] ?? [],
            'model' => $body['model'] ?? $model,
            'usage' => $body['usage'] ?? [],
        ];
    }

    public function chatStream(array $messages, array $options = []): \Generator
    {
        $model = $this->resolveModel($options);
        $maxTokens = $this->resolveMaxTokens($options);
        $temperature = $this->resolveTemperature($options);

        if ($this->systemPrompt && !$this->hasSystemMessage($messages)) {
            array_unshift($messages, ['role' => 'system', 'content' => $this->systemPrompt]);
        }

        $payload = array_merge([
            'model'         => $model,
            'max_tokens'    => $maxTokens,
            'temperature'   => $temperature,
            'messages'      => $messages,
            'stream'        => true,
            // include_usage:true makes OpenAI emit a final chunk with the
            // token totals — without this, billing has no token counts on
            // streamed responses. xAI / OpenAI-compat servers ignore unknown
            // fields, so this is safe to send unconditionally.
            'stream_options' => ['include_usage' => true],
        ], $this->extraParams);

        $stream = $this->streamGuzzle('POST', '/v1/chat/completions', ['json' => $payload]);

        yield from self::translateOpenAiStream(
            SseFrameParser::parse($stream),
            $this->getProviderName(),
            $model,
        );
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Translate a generator of raw SSE frames from /v1/chat/completions into
     * the unified event shape. Pure — exposed for unit testing.
     *
     * @param iterable<int, array{event: ?string, data: string}> $frames
     * @return \Generator<int, array{type: string, ...}>
     */
    public static function translateOpenAiStream(iterable $frames, string $providerName, string $model): \Generator
    {
        $finishReason = null;

        foreach ($frames as $frame) {
            $data = $frame['data'];

            // OpenAI terminates the stream with a literal "[DONE]" frame
            // — not a JSON object — after any final usage chunk.
            if ($data === '[DONE]') {
                if ($finishReason !== null) {
                    yield ['type' => 'finish', 'reason' => $finishReason];
                }
                yield ['type' => 'done'];
                return;
            }

            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                // Malformed chunk — skip rather than abort the stream.
                continue;
            }

            // Provider-emitted error mid-stream (rare but documented).
            if (isset($decoded['error'])) {
                $msg = is_array($decoded['error'])
                    ? ($decoded['error']['message'] ?? json_encode($decoded['error']))
                    : (string) $decoded['error'];
                yield ['type' => 'error', 'message' => (string) $msg];
                return;
            }

            // Chat completion chunks have one entry in choices[] with a
            // `delta` object that may carry partial content + a finish_reason.
            $choice = $decoded['choices'][0] ?? null;
            if ($choice !== null) {
                $delta = $choice['delta'] ?? [];
                if (isset($delta['content']) && $delta['content'] !== '') {
                    yield ['type' => 'delta', 'text' => (string) $delta['content']];
                }
                if (!empty($choice['finish_reason'])) {
                    // Defer emission until [DONE] / end so the order is
                    // consistently delta* → usage → finish → done.
                    $finishReason = (string) $choice['finish_reason'];
                }
            }

            // Final usage chunk (only when stream_options.include_usage:true).
            // OpenAI sends `choices: []` with `usage: {...}` populated.
            if (isset($decoded['usage']['prompt_tokens']) || isset($decoded['usage']['completion_tokens'])) {
                yield [
                    'type'          => 'usage',
                    'input_tokens'  => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
                    'output_tokens' => (int) ($decoded['usage']['completion_tokens'] ?? 0),
                ];
            }
        }

        // Stream ended without [DONE] — emit a terminal sentinel anyway so
        // the resource layer can finalize billing.
        if ($finishReason !== null) {
            yield ['type' => 'finish', 'reason' => $finishReason];
        }
        yield ['type' => 'done'];
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && !empty($this->baseUrl);
    }

    public function getProviderName(): string
    {
        return 'openai_compatible';
    }

    private function hasSystemMessage(array $messages): bool
    {
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                return true;
            }
        }
        return false;
    }
}
