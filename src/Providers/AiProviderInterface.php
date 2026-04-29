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
     * Streaming chat — yields incremental events as the model generates.
     *
     * Each yielded event is an associative array tagged by `type`:
     *   - `['type' => 'delta', 'text' => string]` — partial text
     *   - `['type' => 'usage', 'input_tokens' => int, 'output_tokens' => int]`
     *      — token counts (typically delivered once near stream end)
     *   - `['type' => 'finish', 'reason' => string]` — final stop reason
     *   - `['type' => 'done']` — terminal sentinel; no more events follow
     *   - `['type' => 'error', 'message' => string]` — provider-emitted error
     *      mid-stream; the generator will return after yielding this
     *
     * Callers MUST iterate the generator to completion (or call `return`)
     * so any underlying HTTP connection is released. The generator does NOT
     * persist usage to the AiUsageLog — the caller is responsible for that
     * (so partial-disconnect billing can be handled at the resource layer).
     *
     * Providers without streaming support throw \LogicException — see
     * {@see BaseAiProvider::chatStream()} default.
     *
     * @param array<array{role: string, content: string}> $messages
     * @param array{max_tokens?: int, temperature?: float, model?: string} $options
     *
     * @return \Generator<int, array{type: string, ...}>
     *
     * @throws \RuntimeException|\LogicException
     */
    public function chatStream(array $messages, array $options = []): \Generator;

    /**
     * Whether this provider supports tool/function calling.
     */
    public function supportsToolUse(): bool;

    /**
     * Whether this provider supports streaming chat.
     */
    public function supportsStreaming(): bool;

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
