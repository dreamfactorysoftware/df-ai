<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shared functionality for all AI providers.
 *
 * Handles Guzzle client creation, error wrapping, and response helpers.
 */
abstract class BaseAiProvider implements AiProviderInterface
{
    protected Client $client;
    protected string $defaultModel;
    protected int $defaultMaxTokens;
    protected float $defaultTemperature;
    protected ?string $systemPrompt;
    protected array $extraHeaders;
    protected array $extraParams;

    public function __construct(
        protected readonly string $baseUrl,
        protected readonly ?string $apiKey,
        string $defaultModel = '',
        int $maxTokens = 1024,
        float $temperature = 0.7,
        int $timeout = 30,
        ?string $systemPrompt = null,
        array $extraHeaders = [],
        array $extraParams = [],
        ?string $organizationId = null,
    ) {
        $this->defaultModel = $defaultModel;
        $this->defaultMaxTokens = $maxTokens;
        $this->defaultTemperature = $temperature;
        $this->systemPrompt = $systemPrompt;
        $this->extraHeaders = $extraHeaders;
        $this->extraParams = $extraParams;

        $headers = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $this->buildAuthHeaders($organizationId), $extraHeaders);

        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout'  => $timeout,
            'headers'  => $headers,
        ]);
    }

    /**
     * Build provider-specific auth headers. Override in subclasses.
     */
    protected function buildAuthHeaders(?string $organizationId): array
    {
        return [];
    }

    /**
     * Resolve the model to use: request override > service default.
     */
    protected function resolveModel(array $options): string
    {
        return $options['model'] ?? $this->defaultModel;
    }

    protected function resolveMaxTokens(array $options): int
    {
        return (int) ($options['max_tokens'] ?? $this->defaultMaxTokens);
    }

    protected function resolveTemperature(array $options): float
    {
        return (float) ($options['temperature'] ?? $this->defaultTemperature);
    }

    /** Maximum number of retry attempts for retryable errors. */
    protected int $maxRetries = 3;

    /** Base delay in seconds for exponential backoff (doubles each attempt). */
    protected float $retryBaseDelay = 1.0;

    /**
     * Wrap a Guzzle call with consistent error handling and retry logic.
     *
     * Retryable errors (429, 5xx, timeouts) are retried up to $maxRetries times
     * with exponential backoff. Non-retryable errors (401, 403, 400, 422) throw
     * immediately.
     *
     * @throws AiProviderException
     */
    protected function request(string $method, string $uri, array $options = []): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = $this->client->request($method, $uri, $options);
                $body = json_decode($response->getBody()->getContents(), true);

                if (!is_array($body)) {
                    throw new RuntimeException('Provider returned non-JSON response');
                }

                return $body;
            } catch (GuzzleException $e) {
                $lastException = AiProviderException::fromGuzzleException(
                    $this->getProviderName(),
                    $e,
                );

                if (!$lastException->isRetryable() || $attempt === $this->maxRetries) {
                    throw $lastException;
                }

                $delay = $lastException->getRetryAfterSeconds()
                    ?? $this->retryBaseDelay * (2 ** ($attempt - 1));

                $this->logRetry($attempt, $delay, $lastException);

                usleep((int) ($delay * 1_000_000));
            }
        }

        // Should never reach here, but just in case
        throw $lastException ?? new AiProviderException(
            sprintf('%s: request failed after %d attempts', $this->getProviderName(), $this->maxRetries),
        );
    }

    /**
     * Log a retry attempt. Uses Laravel's Log facade when available,
     * falls back to error_log for environments without Laravel.
     */
    protected function logRetry(int $attempt, float $delay, AiProviderException $exception): void
    {
        $message = sprintf(
            'AI provider %s request failed (attempt %d/%d), retrying in %.1fs: %s',
            $this->getProviderName(),
            $attempt,
            $this->maxRetries,
            $delay,
            $exception->getMessage(),
        );

        try {
            Log::warning($message);
        } catch (\Throwable) {
            error_log($message);
        }
    }

    /**
     * Default chatWithTools implementation — throws if not supported.
     */
    public function chatWithTools(array $messages, array $tools, array $options = []): array
    {
        throw new \LogicException(
            sprintf('Provider "%s" does not support tool/function calling.', $this->getProviderName())
        );
    }

    /**
     * Default chatStream implementation — throws if not supported.
     *
     * Subclasses that implement streaming override this and use
     * {@see streamGuzzle()} to open the upstream connection.
     */
    public function chatStream(array $messages, array $options = []): \Generator
    {
        throw new \LogicException(
            sprintf('Provider "%s" does not support streaming chat.', $this->getProviderName())
        );
        // Unreachable, but PHP requires `yield` for the method body to have
        // a Generator return type — the throw above pre-empts execution.
        yield;
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    /**
     * Open a streaming Guzzle request and return the raw PSR-7 stream so
     * the caller can iterate frames. NO retry logic here — streaming
     * responses can't be safely replayed once bytes have been delivered to
     * the upstream client, so the provider gets one shot.
     *
     * 4xx/5xx HTTP errors are caught and re-thrown as AiProviderException so
     * resource code can log them through the existing error path.
     *
     * @param array $options Guzzle request options. `stream` is forced true.
     *
     * @throws AiProviderException
     */
    protected function streamGuzzle(string $method, string $uri, array $options = []): \Psr\Http\Message\StreamInterface
    {
        try {
            $options['stream'] = true;
            $response = $this->client->request($method, $uri, $options);
            return $response->getBody();
        } catch (GuzzleException $e) {
            throw AiProviderException::fromGuzzleException($this->getProviderName(), $e);
        }
    }

    /**
     * Default embeddings implementation — throws if not supported.
     */
    public function embeddings(string|array $input, array $options = []): array
    {
        throw new \LogicException(
            sprintf('Provider "%s" does not support embeddings.', $this->getProviderName())
        );
    }

    public function supportsToolUse(): bool
    {
        return false;
    }

    public function buildToolResultMessage(string $toolCallId, string $toolName, mixed $result, bool $isError = false): array
    {
        throw new \LogicException(
            sprintf('Provider "%s" does not support tool use.', $this->getProviderName())
        );
    }

    public function buildAssistantToolCallMessage(?string $content, array $toolCalls): array
    {
        throw new \LogicException(
            sprintf('Provider "%s" does not support tool use.', $this->getProviderName())
        );
    }

    /**
     * Default isAvailable — checks that we have the minimum config.
     */
    public function isAvailable(): bool
    {
        return !empty($this->baseUrl);
    }
}
