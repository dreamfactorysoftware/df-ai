<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

use RuntimeException;

/**
 * Anthropic (Claude) provider.
 *
 * Uses the Anthropic Messages API at /v1/messages.
 */
class AnthropicProvider extends BaseAiProvider
{
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
            'temperature' => $temperature,
            'messages'   => $filteredMessages,
        ], $this->extraParams);

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

        // Extract system messages.
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

        // Convert tools to Anthropic format.
        $anthropicTools = [];
        foreach ($tools as $tool) {
            $anthropicTools[] = [
                'name'        => $tool['name'],
                'description' => $tool['description'] ?? '',
                'input_schema' => $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }

        $payload = array_merge([
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'messages'    => $filteredMessages,
            'tools'       => $anthropicTools,
        ], $this->extraParams);

        if ($systemContent) {
            $payload['system'] = $systemContent;
        }

        $body = $this->request('POST', '/v1/messages', ['json' => $payload]);

        // Parse content blocks — may contain text and/or tool_use blocks.
        $textContent = null;
        $toolCalls = null;

        foreach ($body['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $textContent = ($textContent ?? '') . $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls ??= [];
                $toolCalls[] = [
                    'id'    => $block['id'],
                    'name'  => $block['name'],
                    'input' => $block['input'] ?? [],
                ];
            }
        }

        return [
            'content'       => $textContent,
            'tool_calls'    => $toolCalls,
            'provider'      => 'anthropic',
            'model'         => $body['model'] ?? $model,
            'input_tokens'  => $body['usage']['input_tokens'] ?? 0,
            'output_tokens' => $body['usage']['output_tokens'] ?? 0,
            'finish_reason' => $body['stop_reason'] ?? 'unknown',
        ];
    }

    public function supportsToolUse(): bool
    {
        return true;
    }

    public function buildToolResultMessage(string $toolCallId, string $toolName, mixed $result, bool $isError = false): array
    {
        return [
            'role'    => 'user',
            'content' => [
                [
                    'type'       => 'tool_result',
                    'tool_use_id' => $toolCallId,
                    'content'    => is_string($result) ? $result : json_encode($result),
                    'is_error'   => $isError,
                ],
            ],
        ];
    }

    public function buildAssistantToolCallMessage(?string $content, array $toolCalls): array
    {
        $blocks = [];
        if ($content !== null && $content !== '') {
            $blocks[] = ['type' => 'text', 'text' => $content];
        }
        foreach ($toolCalls as $tc) {
            $blocks[] = [
                'type'  => 'tool_use',
                'id'    => $tc['id'],
                'name'  => $tc['name'],
                'input' => $tc['input'] ?? [],
            ];
        }

        return ['role' => 'assistant', 'content' => $blocks];
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
