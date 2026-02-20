<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Typed exception for AI provider errors with retryable classification.
 *
 * Static factories classify HTTP errors from AI providers so that the
 * retry logic in BaseAiProvider can decide whether to retry or fail fast.
 */
class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        private readonly string $providerName = '',
        private readonly int $httpStatus = 0,
        private readonly bool $retryable = false,
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * 429 Too Many Requests — always retryable.
     */
    public static function rateLimited(
        string $provider,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf('%s: rate limited (429). Retry after %s seconds.', $provider, $retryAfter ?? 'unknown'),
            429,
            $previous,
            $provider,
            429,
            true,
            $retryAfter,
        );
    }

    /**
     * 5xx server errors — retryable (transient).
     */
    public static function serverError(
        string $provider,
        int $httpStatus,
        string $detail = '',
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf('%s: server error (%d)%s', $provider, $httpStatus, $detail ? ": {$detail}" : ''),
            $httpStatus,
            $previous,
            $provider,
            $httpStatus,
            true,
        );
    }

    /**
     * 401/403 authentication errors — NOT retryable.
     */
    public static function authError(
        string $provider,
        int $httpStatus,
        string $detail = '',
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf('%s: authentication failed (%d)%s', $provider, $httpStatus, $detail ? ": {$detail}" : ''),
            $httpStatus,
            $previous,
            $provider,
            $httpStatus,
            false,
        );
    }

    /**
     * 400/422 bad request — NOT retryable (client error).
     */
    public static function badRequest(
        string $provider,
        int $httpStatus,
        string $detail = '',
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf('%s: bad request (%d)%s', $provider, $httpStatus, $detail ? ": {$detail}" : ''),
            $httpStatus,
            $previous,
            $provider,
            $httpStatus,
            false,
        );
    }

    /**
     * Connection/read timeout — retryable.
     */
    public static function timeout(
        string $provider,
        string $detail = '',
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf('%s: request timed out%s', $provider, $detail ? ": {$detail}" : ''),
            0,
            $previous,
            $provider,
            0,
            true,
        );
    }

    /**
     * Classify a GuzzleException into the appropriate AiProviderException subtype.
     */
    public static function fromGuzzleException(
        string $provider,
        \GuzzleHttp\Exception\GuzzleException $e,
    ): self {
        // Connection timeouts
        if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
            return self::timeout($provider, $e->getMessage(), $e);
        }

        // HTTP errors with a response
        if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $detail = self::extractErrorDetail($body);

            return match (true) {
                $status === 429 => self::rateLimited(
                    $provider,
                    self::parseRetryAfter($response),
                    $e,
                ),
                $status === 401, $status === 403 => self::authError($provider, $status, $detail, $e),
                $status === 400, $status === 422 => self::badRequest($provider, $status, $detail, $e),
                $status >= 500 => self::serverError($provider, $status, $detail, $e),
                default => new self(
                    sprintf('%s: HTTP %d%s', $provider, $status, $detail ? ": {$detail}" : ''),
                    $status,
                    $e,
                    $provider,
                    $status,
                    false,
                ),
            };
        }

        // Fallback for other Guzzle exceptions (transfer errors, etc.)
        return self::timeout($provider, $e->getMessage(), $e);
    }

    /**
     * Parse the Retry-After header value into seconds.
     */
    private static function parseRetryAfter(\Psr\Http\Message\ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');
        if ($header === '') {
            return null;
        }

        // Numeric seconds
        if (is_numeric($header)) {
            return (int) $header;
        }

        // HTTP-date format
        $time = strtotime($header);
        if ($time !== false) {
            return max(0, $time - time());
        }

        return null;
    }

    /**
     * Extract a short error message from a JSON response body.
     */
    private static function extractErrorDetail(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return substr($body, 0, 200);
        }

        // Common error formats across providers
        return $decoded['error']['message']
            ?? $decoded['error']
            ?? $decoded['message']
            ?? $decoded['detail']
            ?? substr($body, 0, 200);
    }
}
