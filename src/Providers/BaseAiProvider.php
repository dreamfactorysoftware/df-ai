<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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

    /**
     * Wrap a Guzzle call with consistent error handling.
     */
    protected function request(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->client->request($method, $uri, $options);
            $body = json_decode($response->getBody()->getContents(), true);

            if (!is_array($body)) {
                throw new RuntimeException('Provider returned non-JSON response');
            }

            return $body;
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                sprintf('%s API request failed: %s', $this->getProviderName(), $e->getMessage()),
                (int) $e->getCode(),
                $e,
            );
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

    /**
     * Default isAvailable — checks that we have the minimum config.
     */
    public function isAvailable(): bool
    {
        return !empty($this->baseUrl);
    }
}
