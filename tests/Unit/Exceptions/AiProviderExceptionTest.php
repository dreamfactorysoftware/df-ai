<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Exceptions;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class AiProviderExceptionTest extends TestCase
{
    public function testRateLimitedIsRetryable(): void
    {
        $e = AiProviderException::rateLimited('anthropic', 30);

        $this->assertTrue($e->isRetryable());
        $this->assertEquals(429, $e->getHttpStatus());
        $this->assertEquals(429, $e->getCode());
        $this->assertEquals('anthropic', $e->getProviderName());
        $this->assertEquals(30, $e->getRetryAfterSeconds());
    }

    public function testServerErrorIsRetryable(): void
    {
        $e = AiProviderException::serverError('openai', 503, 'Service unavailable');

        $this->assertTrue($e->isRetryable());
        $this->assertEquals(503, $e->getHttpStatus());
        $this->assertStringContains('503', $e->getMessage());
        $this->assertNull($e->getRetryAfterSeconds());
    }

    public function testAuthErrorIsNotRetryable(): void
    {
        $e = AiProviderException::authError('anthropic', 401, 'Invalid API key');

        $this->assertFalse($e->isRetryable());
        $this->assertEquals(401, $e->getHttpStatus());
        $this->assertStringContains('authentication failed', $e->getMessage());
    }

    public function testBadRequestIsNotRetryable(): void
    {
        $e = AiProviderException::badRequest('openai', 400, 'Invalid model');

        $this->assertFalse($e->isRetryable());
        $this->assertEquals(400, $e->getHttpStatus());
    }

    public function testTimeoutIsRetryable(): void
    {
        $e = AiProviderException::timeout('ollama', 'Connection timed out');

        $this->assertTrue($e->isRetryable());
        $this->assertEquals(0, $e->getHttpStatus());
    }

    public function testFromGuzzleConnectException(): void
    {
        $request = new Request('POST', 'https://api.anthropic.com/v1/messages');
        $guzzleEx = new ConnectException('Connection timed out', $request);

        $e = AiProviderException::fromGuzzleException('anthropic', $guzzleEx);

        $this->assertTrue($e->isRetryable());
        $this->assertStringContains('timed out', $e->getMessage());
    }

    public function testFromGuzzle429WithRetryAfter(): void
    {
        $request = new Request('POST', 'https://api.anthropic.com/v1/messages');
        $response = new Response(429, ['Retry-After' => '60'], '{"error":"rate limited"}');
        $guzzleEx = new ClientException('Rate limited', $request, $response);

        $e = AiProviderException::fromGuzzleException('anthropic', $guzzleEx);

        $this->assertTrue($e->isRetryable());
        $this->assertEquals(429, $e->getHttpStatus());
        $this->assertEquals(60, $e->getRetryAfterSeconds());
    }

    public function testFromGuzzle401(): void
    {
        $request = new Request('POST', 'https://api.openai.com/v1/completions');
        $response = new Response(401, [], '{"error":{"message":"Invalid API key"}}');
        $guzzleEx = new ClientException('Unauthorized', $request, $response);

        $e = AiProviderException::fromGuzzleException('openai', $guzzleEx);

        $this->assertFalse($e->isRetryable());
        $this->assertEquals(401, $e->getHttpStatus());
        $this->assertStringContains('Invalid API key', $e->getMessage());
    }

    public function testFromGuzzle500(): void
    {
        $request = new Request('POST', 'https://api.openai.com/v1/completions');
        $response = new Response(500, [], '{"error":"Internal server error"}');
        $guzzleEx = new ServerException('Server error', $request, $response);

        $e = AiProviderException::fromGuzzleException('openai', $guzzleEx);

        $this->assertTrue($e->isRetryable());
        $this->assertEquals(500, $e->getHttpStatus());
    }

    public function testFromGuzzle422IsNotRetryable(): void
    {
        $request = new Request('POST', 'https://api.openai.com/v1/completions');
        $response = new Response(422, [], '{"error":{"message":"Invalid parameters"}}');
        $guzzleEx = new ClientException('Unprocessable', $request, $response);

        $e = AiProviderException::fromGuzzleException('openai', $guzzleEx);

        $this->assertFalse($e->isRetryable());
        $this->assertEquals(422, $e->getHttpStatus());
    }

    /**
     * Helper for PHP versions that don't have assertStringContainsString.
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
