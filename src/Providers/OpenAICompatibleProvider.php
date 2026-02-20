<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

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

        // Convert tools to OpenAI function calling format.
        $openaiTools = [];
        foreach ($tools as $tool) {
            $openaiTools[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters'  => $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ];
        }

        $payload = array_merge([
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'messages'    => $messages,
            'tools'       => $openaiTools,
        ], $this->extraParams);

        $body = $this->request('POST', '/v1/chat/completions', ['json' => $payload]);

        if (!isset($body['choices'][0])) {
            throw new RuntimeException(
                $this->getProviderName() . ' API returned unexpected response: '
                . substr((string) json_encode($body), 0, 300)
            );
        }

        $choice = $body['choices'][0];
        $message = $choice['message'] ?? [];

        $toolCalls = null;
        if (!empty($message['tool_calls'])) {
            $toolCalls = [];
            foreach ($message['tool_calls'] as $tc) {
                $toolCalls[] = [
                    'id'    => $tc['id'],
                    'name'  => $tc['function']['name'],
                    'input' => json_decode($tc['function']['arguments'] ?? '{}', true) ?? [],
                ];
            }
        }

        return [
            'content'       => $message['content'] ?? null,
            'tool_calls'    => $toolCalls,
            'provider'      => $this->getProviderName(),
            'model'         => $body['model'] ?? $model,
            'input_tokens'  => $body['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $body['usage']['completion_tokens'] ?? 0,
            'finish_reason' => $choice['finish_reason'] ?? 'unknown',
        ];
    }

    public function supportsToolUse(): bool
    {
        return true;
    }

    public function buildToolResultMessage(string $toolCallId, string $toolName, mixed $result, bool $isError = false): array
    {
        return [
            'role'         => 'tool',
            'tool_call_id' => $toolCallId,
            'content'      => is_string($result) ? $result : json_encode($result),
        ];
    }

    public function buildAssistantToolCallMessage(?string $content, array $toolCalls): array
    {
        $openaiToolCalls = [];
        foreach ($toolCalls as $tc) {
            $openaiToolCalls[] = [
                'id'       => $tc['id'],
                'type'     => 'function',
                'function' => [
                    'name'      => $tc['name'],
                    'arguments' => json_encode($tc['input'] ?? []),
                ],
            ];
        }

        return [
            'role'       => 'assistant',
            'content'    => $content,
            'tool_calls' => $openaiToolCalls,
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
