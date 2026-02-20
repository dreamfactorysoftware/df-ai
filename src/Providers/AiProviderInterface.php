<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

/**
 * Common interface for all AI/LLM providers.
 *
 * Providers normalise different vendor APIs behind a consistent contract so
 * that the rest of the DreamFactory AI stack can work with any model.
 */
interface AiProviderInterface
{
    /**
     * Single-turn completion (prompt in, text out).
     *
     * @param array{
     *     prompt: string,
     *     max_tokens?: int,
     *     temperature?: float,
     *     model?: string,
     * } $options
     *
     * @return array{
     *     content: string,
     *     provider: string,
     *     model: string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     finish_reason: string,
     * }
     *
     * @throws \RuntimeException
     */
    public function complete(array $options): array;

    /**
     * Multi-turn chat (message array in, message out).
     *
     * @param array<array{role: string, content: string}> $messages
     * @param array{max_tokens?: int, temperature?: float, model?: string} $options
     *
     * @return array{
     *     content: string,
     *     provider: string,
     *     model: string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     finish_reason: string,
     * }
     *
     * @throws \RuntimeException
     */
    public function chat(array $messages, array $options = []): array;

    /**
     * List models available from this provider.
     *
     * @return array<array{id: string, name?: string, context_window?: int}>
     */
    public function listModels(): array;

    /**
     * Multi-turn chat with tool/function calling support.
     *
     * When tools are provided the model may return tool_calls instead of
     * (or alongside) text content. The caller is responsible for executing
     * the tool calls and sending the results back as tool-result messages.
     *
     * @param array<array{role: string, content: mixed}> $messages
     * @param array<array{name: string, description: string, parameters: array}> $tools
     * @param array{max_tokens?: int, temperature?: float, model?: string} $options
     *
     * @return array{
     *     content: ?string,
     *     tool_calls: ?array<array{id: string, name: string, arguments: array}>,
     *     provider: string,
     *     model: string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     finish_reason: string,
     * }
     *
     * @throws \RuntimeException|\LogicException
     */
    public function chatWithTools(array $messages, array $tools, array $options = []): array;

    /**
     * Generate text embeddings.
     *
     * @param string|array<string> $input
     * @param array{model?: string} $options
     *
     * @return array{data: array, model: string, usage: array}
     *
     * @throws \RuntimeException|\LogicException
     */
    public function embeddings(string|array $input, array $options = []): array;

    /**
     * Multi-turn chat with tool/function calling support.
     *
     * Messages may include tool results from previous iterations.
     * The provider is responsible for translating the normalized tool
     * format to its native API format.
     *
     * @param array $messages  Conversation messages (may include tool result messages)
     * @param array $tools     Tool definitions in normalized format:
     *                         [{name, description, input_schema: {type, properties, required}}]
     * @param array $options   Same options as chat() plus tool-specific options
     *
     * @return array{
     *     content: ?string,
     *     tool_calls: ?array<array{id: string, name: string, input: array}>,
     *     provider: string,
     *     model: string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     finish_reason: string,
     * }
     *
     * @throws \RuntimeException|\LogicException
     */
    public function chatWithTools(array $messages, array $tools, array $options = []): array;

    /**
     * Whether this provider supports tool/function calling.
     */
    public function supportsToolUse(): bool;

    /**
     * Build a tool result message in the provider's native format.
     *
     * Used by the agentic loop to feed tool execution results back to the model.
     */
    public function buildToolResultMessage(string $toolCallId, string $toolName, mixed $result, bool $isError = false): array;

    /**
     * Build an assistant message containing tool calls in the provider's native format.
     *
     * Used to append the assistant's tool_use response to the conversation history.
     */
    public function buildAssistantToolCallMessage(?string $content, array $toolCalls): array;

    /**
     * Check whether this provider is reachable and configured.
     */
    public function isAvailable(): bool;

    /**
     * Provider identifier (e.g. 'anthropic', 'openai', 'xai', 'ollama').
     */
    public function getProviderName(): string;
}
