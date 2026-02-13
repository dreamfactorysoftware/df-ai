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
     * Check whether this provider is reachable and configured.
     */
    public function isAvailable(): bool;

    /**
     * Provider identifier (e.g. 'anthropic', 'openai', 'xai', 'ollama').
     */
    public function getProviderName(): string;
}
