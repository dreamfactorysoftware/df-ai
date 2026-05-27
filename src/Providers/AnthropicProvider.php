<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

use DreamFactory\Core\AI\Providers\Streaming\SseFrameParser;
use RuntimeException;

/**
 * Anthropic (Claude) provider.
 *
 * Uses the Anthropic Messages API at /v1/messages.
 */
class AnthropicProvider extends BaseAiProvider
{
    /**
     * Model prefixes that reject the `temperature` parameter. Anthropic
     * deprecated `temperature` on the Opus 4.7 line — sending it returns
     * HTTP 400 ("temperature is deprecated for this model"). Matched by
     * prefix so dated snapshots (claude-opus-4-7-YYYYMMDD) are covered too.
     *
     * @var string[]
     */
    private const TEMPERATURE_UNSUPPORTED_PREFIXES = ['claude-opus-4-7'];

    /**
     * Whether the given model accepts the `temperature` request parameter.
     */
    protected function supportsTemperature(string $model): bool
    {
        foreach (self::TEMPERATURE_UNSUPPORTED_PREFIXES as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return false;
            }
        }
        return true;
    }

    protected function buildAuthHeaders(?string $organizationId): array
    {
        $headers = [];
        if ($this->apiKey) {
            $headers['x-api-key'] = $this->apiKey;
        }
        $headers['anthropic-version'] = config('df-ai.anthropic_version', '2023-06-01');
        return $headers;
    }

    public function complete(array $options): array
    {
        $messages = [['role' => 'user', 'content' => $options['prompt'] ?? '']];
        return $this->chat($messages, $options);
    }

    public function chat(array $messages, array $options = []): array
    {
        $model = $this->resolveModel($options);
        $maxTokens = $this->resolveMaxTokens($options);
        $temperature = $this->resolveTemperature($options);

        // Anthropic expects system prompt as a top-level parameter, not in messages.
        $systemContent = null;
        $filteredMessages = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemContent = ($systemContent ? $systemContent . "\n" : '') . ($msg['content'] ?? '');
            } else {
                $filteredMessages[] = $msg;
            }
        }

        // Prepend the service-level system prompt if configured.
        if ($this->systemPrompt) {
            $systemContent = $this->systemPrompt . ($systemContent ? "\n" . $systemContent : '');
        }

        $payload = array_merge([
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $filteredMessages,
        ], $this->extraParams);

        if ($this->supportsTemperature($model)) {
            $payload['temperature'] = $temperature;
        }

        if ($systemContent) {
            $payload['system'] = $systemContent;
        }

        $body = $this->request('POST', '/v1/messages', ['json' => $payload]);

        if (!isset($body['content'][0]['text'])) {
            throw new RuntimeException(
                'Anthropic API returned unexpected response: ' . substr((string) json_encode($body), 0, 300)
            );
        }

        return [
            'content'       => $body['content'][0]['text'],
            'provider'      => 'anthropic',
            'model'         => $body['model'] ?? $model,
            'input_tokens'  => $body['usage']['input_tokens'] ?? 0,
            'output_tokens' => $body['usage']['output_tokens'] ?? 0,
            'finish_reason' => $body['stop_reason'] ?? 'unknown',
        ];
    }

    public function chatWithTools(array $messages, array $tools, array $options = []): array
    {
        $model = $this->resolveModel($options);
        $maxTokens = $this->resolveMaxTokens($options);
        $temperature = $this->resolveTemperature($options);

        // Convert generic messages to Anthropic format.
        // - System messages → extracted to top-level 'system' param
        // - Assistant messages with tool_calls → content blocks with tool_use
        // - Tool result messages (role:tool) → user message with tool_result content blocks
        $systemContent = null;
        $filteredMessages = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';

            if ($role === 'system') {
                $systemContent = ($systemContent ? $systemContent . "\n" : '') . ($msg['content'] ?? '');
                continue;
            }

            if ($role === 'assistant' && !empty($msg['tool_calls'])) {
                // Assistant message that includes tool_use requests.
                $contentBlocks = [];
                if (!empty($msg['content'])) {
                    $contentBlocks[] = ['type' => 'text', 'text' => $msg['content']];
                }
                foreach ($msg['tool_calls'] as $tc) {
                    $args = $tc['arguments'] ?? [];
                    // Anthropic requires 'input' to be a JSON object (dict), never an array.
                    $contentBlocks[] = [
                        'type'  => 'tool_use',
                        'id'    => $tc['id'] ?? '',
                        'name'  => $tc['name'] ?? '',
                        'input' => !empty($args) ? (object) $args : (object) [],
                    ];
                }
                $filteredMessages[] = ['role' => 'assistant', 'content' => $contentBlocks];
                continue;
            }

            if ($role === 'tool') {
                // Tool result → Anthropic wants role:user with type:tool_result blocks.
                // Merge consecutive tool results into one user message.
                $toolResultBlock = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $msg['tool_call_id'] ?? '',
                    'content'     => $msg['content'] ?? '',
                ];
                // Check if the last filtered message is already a user tool_result message.
                $lastIdx = count($filteredMessages) - 1;
                if ($lastIdx >= 0
                    && $filteredMessages[$lastIdx]['role'] === 'user'
                    && is_array($filteredMessages[$lastIdx]['content'])
                    && ($filteredMessages[$lastIdx]['content'][0]['type'] ?? '') === 'tool_result'
                ) {
                    $filteredMessages[$lastIdx]['content'][] = $toolResultBlock;
                } else {
                    $filteredMessages[] = ['role' => 'user', 'content' => [$toolResultBlock]];
                }
                continue;
            }

            // Regular user/assistant text messages.
            $filteredMessages[] = ['role' => $role, 'content' => $msg['content'] ?? ''];
        }

        if ($this->systemPrompt) {
            $systemContent = $this->systemPrompt . ($systemContent ? "\n" . $systemContent : '');
        }

        $payload = array_merge([
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'messages'    => $filteredMessages,
            'tools'       => ToolDefinition::toAnthropicArray($tools),
        ], $this->extraParams);

        if ($this->supportsTemperature($model)) {
            $payload['temperature'] = $temperature;
        }

        if ($systemContent) {
            $payload['system'] = $systemContent;
        }

        $body = $this->request('POST', '/v1/messages', ['json' => $payload]);

        // Parse content blocks — may contain text and/or tool_use blocks.
        $textParts = [];
        $toolCalls = [];
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $textParts[] = $block['text'];
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = ToolCall::fromAnthropic($block)->toArray();
            }
        }

        return [
            'content'       => !empty($textParts) ? implode('', $textParts) : null,
            'tool_calls'    => !empty($toolCalls) ? $toolCalls : null,
            'provider'      => 'anthropic',
            'model'         => $body['model'] ?? $model,
            'input_tokens'  => $body['usage']['input_tokens'] ?? 0,
            'output_tokens' => $body['usage']['output_tokens'] ?? 0,
            'finish_reason' => $body['stop_reason'] ?? 'unknown',
        ];
    }

    public function chatStream(array $messages, array $options = []): \Generator
    {
        $model = $this->resolveModel($options);
        $maxTokens = $this->resolveMaxTokens($options);
        $temperature = $this->resolveTemperature($options);

        // Same system-prompt extraction as chat() — Anthropic wants system as
        // a top-level field, not a message.
        $systemContent = null;
        $filteredMessages = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemContent = ($systemContent ? $systemContent . "\n" : '') . ($msg['content'] ?? '');
            } else {
                $filteredMessages[] = $msg;
            }
        }
        if ($this->systemPrompt) {
            $systemContent = $this->systemPrompt . ($systemContent ? "\n" . $systemContent : '');
        }

        $payload = array_merge([
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'messages'    => $filteredMessages,
            'stream'      => true,
        ], $this->extraParams);
        if ($this->supportsTemperature($model)) {
            $payload['temperature'] = $temperature;
        }
        if ($systemContent) {
            $payload['system'] = $systemContent;
        }

        $stream = $this->streamGuzzle('POST', '/v1/messages', ['json' => $payload]);

        yield from self::translateAnthropicStream(SseFrameParser::parse($stream));
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Translate Anthropic's typed SSE events into the unified event shape.
     * Pure — exposed for unit testing.
     *
     * Anthropic's stream protocol (v2023-06-01):
     *   message_start          — usage.input_tokens populated; output starts at 1
     *   content_block_start    — start of a text/tool_use block
     *   content_block_delta    — text_delta or input_json_delta payloads
     *   content_block_stop
     *   message_delta          — final stop_reason + final usage.output_tokens
     *   message_stop           — terminal
     *   ping                   — keepalive (ignored)
     *   error                  — provider-emitted mid-stream error
     *
     * @param iterable<int, array{event: ?string, data: string}> $frames
     * @return \Generator<int, array{type: string, ...}>
     */
    public static function translateAnthropicStream(iterable $frames): \Generator
    {
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = null;

        foreach ($frames as $frame) {
            $eventName = $frame['event'];
            $decoded = json_decode($frame['data'], true);
            if (!is_array($decoded)) {
                continue;
            }

            // Mid-stream errors are emitted with event:error.
            if ($eventName === 'error' || ($decoded['type'] ?? '') === 'error') {
                $msg = $decoded['error']['message'] ?? ($decoded['message'] ?? 'unknown anthropic error');
                yield ['type' => 'error', 'message' => (string) $msg];
                return;
            }

            switch ($eventName) {
                case 'message_start':
                    $inputTokens = (int) ($decoded['message']['usage']['input_tokens'] ?? 0);
                    break;

                case 'content_block_delta':
                    $delta = $decoded['delta'] ?? [];
                    // text_delta is the only delta type relevant to plain
                    // chat — input_json_delta only appears when tools are
                    // active, and chatStream() is text-only for now.
                    if (($delta['type'] ?? '') === 'text_delta'
                        && isset($delta['text'])
                        && $delta['text'] !== ''
                    ) {
                        yield ['type' => 'delta', 'text' => (string) $delta['text']];
                    }
                    break;

                case 'message_delta':
                    if (!empty($decoded['delta']['stop_reason'])) {
                        $finishReason = (string) $decoded['delta']['stop_reason'];
                    }
                    if (isset($decoded['usage']['output_tokens'])) {
                        $outputTokens = (int) $decoded['usage']['output_tokens'];
                    }
                    break;

                case 'message_stop':
                    yield [
                        'type'          => 'usage',
                        'input_tokens'  => $inputTokens,
                        'output_tokens' => $outputTokens,
                    ];
                    if ($finishReason !== null) {
                        yield ['type' => 'finish', 'reason' => $finishReason];
                    }
                    yield ['type' => 'done'];
                    return;

                // ping, content_block_start, content_block_stop → no output.
            }
        }

        // Stream ended without message_stop — emit terminal sentinels anyway
        // so the resource layer can finalize billing with what we've got.
        yield [
            'type'          => 'usage',
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
        if ($finishReason !== null) {
            yield ['type' => 'finish', 'reason' => $finishReason];
        }
        yield ['type' => 'done'];
    }

    public function listModels(): array
    {
        $body = $this->request('GET', '/v1/models');
        $models = [];
        foreach ($body['data'] ?? [] as $m) {
            $models[] = [
                'id'             => $m['id'] ?? '',
                'name'           => $m['display_name'] ?? $m['id'] ?? '',
                'context_window' => $m['context_window'] ?? null,
            ];
        }
        return $models;
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getProviderName(): string
    {
        return 'anthropic';
    }
}
