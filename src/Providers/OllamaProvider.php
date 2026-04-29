<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

use DreamFactory\Core\AI\Providers\Streaming\SseFrameParser;
use RuntimeException;

/**
 * Ollama (local LLM) provider.
 *
 * Ollama has its own REST API format:
 *   POST /api/generate   (single turn)
 *   POST /api/chat       (multi-turn)
 *   GET  /api/tags       (list models)
 *   POST /api/embed       (embeddings)
 *
 * No API key required — Ollama runs locally.
 */
class OllamaProvider extends BaseAiProvider
{
    public function complete(array $options): array
    {
        $model = $this->resolveModel($options);
        $prompt = $options['prompt'] ?? '';

        if ($this->systemPrompt) {
            $prompt = $this->systemPrompt . "\n\n" . $prompt;
        }

        $payload = array_merge([
            'model'  => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature'  => $this->resolveTemperature($options),
                'num_predict'  => $this->resolveMaxTokens($options),
            ],
        ], $this->extraParams);

        $body = $this->request('POST', '/api/generate', ['json' => $payload]);

        return [
            'content'       => $body['response'] ?? '',
            'provider'      => 'ollama',
            'model'         => $body['model'] ?? $model,
            'input_tokens'  => $body['prompt_eval_count'] ?? 0,
            'output_tokens' => $body['eval_count'] ?? 0,
            'finish_reason' => $body['done'] ?? false ? 'stop' : 'unknown',
        ];
    }

    public function chat(array $messages, array $options = []): array
    {
        $model = $this->resolveModel($options);

        // Prepend service-level system prompt if needed.
        if ($this->systemPrompt) {
            $hasSystem = false;
            foreach ($messages as $msg) {
                if (($msg['role'] ?? '') === 'system') {
                    $hasSystem = true;
                    break;
                }
            }
            if (!$hasSystem) {
                array_unshift($messages, ['role' => 'system', 'content' => $this->systemPrompt]);
            }
        }

        $payload = array_merge([
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => [
                'temperature' => $this->resolveTemperature($options),
                'num_predict' => $this->resolveMaxTokens($options),
            ],
        ], $this->extraParams);

        $body = $this->request('POST', '/api/chat', ['json' => $payload]);

        if (!isset($body['message']['content'])) {
            throw new RuntimeException(
                'Ollama returned unexpected response: ' . substr((string) json_encode($body), 0, 300)
            );
        }

        return [
            'content'       => $body['message']['content'],
            'provider'      => 'ollama',
            'model'         => $body['model'] ?? $model,
            'input_tokens'  => $body['prompt_eval_count'] ?? 0,
            'output_tokens' => $body['eval_count'] ?? 0,
            'finish_reason' => ($body['done'] ?? false) ? 'stop' : 'unknown',
        ];
    }

    public function chatWithTools(array $messages, array $tools, array $options = []): array
    {
        $model = $this->resolveModel($options);

        if ($this->systemPrompt) {
            $hasSystem = false;
            foreach ($messages as $msg) {
                if (($msg['role'] ?? '') === 'system') {
                    $hasSystem = true;
                    break;
                }
            }
            if (!$hasSystem) {
                array_unshift($messages, ['role' => 'system', 'content' => $this->systemPrompt]);
            }
        }

        // Ollama v0.4+ supports OpenAI-compatible tool format.
        $payload = array_merge([
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
            'tools'    => ToolDefinition::toOpenAIArray($tools),
            'options'  => [
                'temperature' => $this->resolveTemperature($options),
                'num_predict' => $this->resolveMaxTokens($options),
            ],
        ], $this->extraParams);

        $body = $this->request('POST', '/api/chat', ['json' => $payload]);

        if (!isset($body['message'])) {
            throw new RuntimeException(
                'Ollama returned unexpected response: ' . substr((string) json_encode($body), 0, 300)
            );
        }

        $message = $body['message'];

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
            'provider'      => 'ollama',
            'model'         => $body['model'] ?? $model,
            'input_tokens'  => $body['prompt_eval_count'] ?? 0,
            'output_tokens' => $body['eval_count'] ?? 0,
            'finish_reason' => ($body['done'] ?? false) ? 'stop' : 'unknown',
        ];
    }

    public function chatStream(array $messages, array $options = []): \Generator
    {
        $model = $this->resolveModel($options);

        if ($this->systemPrompt) {
            $hasSystem = false;
            foreach ($messages as $msg) {
                if (($msg['role'] ?? '') === 'system') {
                    $hasSystem = true;
                    break;
                }
            }
            if (!$hasSystem) {
                array_unshift($messages, ['role' => 'system', 'content' => $this->systemPrompt]);
            }
        }

        $payload = array_merge([
            'model'    => $model,
            'messages' => $messages,
            // Ollama streams when stream:true (and only then — defaults vary
            // across versions, so set it explicitly).
            'stream'   => true,
            'options'  => [
                'temperature' => $this->resolveTemperature($options),
                'num_predict' => $this->resolveMaxTokens($options),
            ],
        ], $this->extraParams);

        $stream = $this->streamGuzzle('POST', '/api/chat', ['json' => $payload]);

        yield from self::translateOllamaStream(SseFrameParser::parseNdjson($stream));
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Translate Ollama's NDJSON chat stream into the unified event shape.
     * Pure — exposed for unit testing.
     *
     * Ollama frames look like:
     *   {"model":"...","message":{"role":"assistant","content":"hi"},"done":false}
     *   ...
     *   {"model":"...","done":true,"prompt_eval_count":4,"eval_count":7,"done_reason":"stop"}
     *
     * @param iterable<int, array<string, mixed>> $rows
     * @return \Generator<int, array{type: string, ...}>
     */
    public static function translateOllamaStream(iterable $rows): \Generator
    {
        foreach ($rows as $row) {
            // Some Ollama versions emit an `error` field on a streaming row
            // when the model can't load.
            if (!empty($row['error'])) {
                yield ['type' => 'error', 'message' => (string) $row['error']];
                return;
            }

            // Incremental content token (only when not done).
            if (empty($row['done']) && isset($row['message']['content'])) {
                $text = (string) $row['message']['content'];
                if ($text !== '') {
                    yield ['type' => 'delta', 'text' => $text];
                }
                continue;
            }

            // Terminal frame.
            if (!empty($row['done'])) {
                yield [
                    'type'          => 'usage',
                    'input_tokens'  => (int) ($row['prompt_eval_count'] ?? 0),
                    'output_tokens' => (int) ($row['eval_count'] ?? 0),
                ];
                yield [
                    'type'   => 'finish',
                    'reason' => (string) ($row['done_reason'] ?? 'stop'),
                ];
                yield ['type' => 'done'];
                return;
            }
        }

        // Stream ended with no done:true frame — emit terminal sentinels so
        // the resource layer can still close out billing.
        yield ['type' => 'usage', 'input_tokens' => 0, 'output_tokens' => 0];
        yield ['type' => 'done'];
    }

    public function listModels(): array
    {
        $body = $this->request('GET', '/api/tags');
        $models = [];
        foreach ($body['models'] ?? [] as $m) {
            $models[] = [
                'id'             => $m['name'] ?? '',
                'name'           => $m['name'] ?? '',
                'context_window' => $m['details']['context_length'] ?? null,
            ];
        }
        return $models;
    }

    public function embeddings(string|array $input, array $options = []): array
    {
        $model = $options['model'] ?? $this->defaultModel;
        $inputs = is_array($input) ? $input : [$input];

        $payload = [
            'model' => $model,
            'input' => $inputs,
        ];

        $body = $this->request('POST', '/api/embed', ['json' => $payload]);

        $data = [];
        foreach ($body['embeddings'] ?? [] as $i => $embedding) {
            $data[] = ['embedding' => $embedding, 'index' => $i];
        }

        return [
            'data'  => $data,
            'model' => $body['model'] ?? $model,
            'usage' => [],
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->baseUrl);
    }

    public function getProviderName(): string
    {
        return 'ollama';
    }
}
