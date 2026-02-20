<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Providers;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use DreamFactory\Core\AI\Providers\BaseAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Test-only concrete subclass that exposes the retry logic via a public method
 * and allows injecting a mock Guzzle client.
 */
class TestableProvider extends BaseAiProvider
{
    public function __construct()
    {
        // Skip parent constructor — we'll inject the client manually.
    }

    public function setClient(Client $client): void
    {
        $this->client = $client;
    }

    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = $maxRetries;
    }

    public function setRetryBaseDelay(float $delay): void
    {
        $this->retryBaseDelay = $delay;
    }

    /**
     * Expose the protected request() method for testing.
     */
    public function doRequest(string $method, string $uri, array $options = []): array
    {
        return $this->request($method, $uri, $options);
    }

    public function complete(array $options): array { return []; }
    public function chat(array $messages, array $options = []): array { return []; }
    public function listModels(): array { return []; }
    public function getProviderName(): string { return 'test'; }
}

class BaseAiProviderRetryTest extends TestCase
{
    private function createProvider(MockHandler $mock, int $maxRetries = 3, float $retryDelay = 0.001): TestableProvider
    {
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $provider = new TestableProvider();
        $provider->setClient($client);
        $provider->setMaxRetries($maxRetries);
        $provider->setRetryBaseDelay($retryDelay); // Near-zero delay for tests
        return $provider;
    }

    public function testSuccessfulRequestOnFirstAttempt(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"result":"ok"}'),
        ]);

        $provider = $this->createProvider($mock);
        $result = $provider->doRequest('POST', '/test');

        $this->assertEquals(['result' => 'ok'], $result);
    }

    public function testRetryOn429ThenSuccess(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '1'], '{"error":"rate limited"}'),
            new Response(200, [], '{"result":"ok"}'),
        ]);

        $provider = $this->createProvider($mock);
        $result = $provider->doRequest('POST', '/test');

        $this->assertEquals(['result' => 'ok'], $result);
    }

    public function testRetryOn500ThenSuccess(): void
    {
        $mock = new MockHandler([
            new Response(500, [], '{"error":"internal error"}'),
            new Response(500, [], '{"error":"internal error"}'),
            new Response(200, [], '{"result":"ok"}'),
        ]);

        $provider = $this->createProvider($mock);
        $result = $provider->doRequest('POST', '/test');

        $this->assertEquals(['result' => 'ok'], $result);
    }

    public function testNoRetryOn401(): void
    {
        $mock = new MockHandler([
            new Response(401, [], '{"error":{"message":"Invalid API key"}}'),
        ]);

        $provider = $this->createProvider($mock);

        $this->expectException(AiProviderException::class);

        $provider->doRequest('POST', '/test');
    }

    public function testNoRetryOn400(): void
    {
        $mock = new MockHandler([
            new Response(400, [], '{"error":"bad request"}'),
        ]);

        $provider = $this->createProvider($mock);

        $this->expectException(AiProviderException::class);

        $provider->doRequest('POST', '/test');
    }

    public function testExhaustsRetriesOn500(): void
    {
        $mock = new MockHandler([
            new Response(500, [], '{"error":"internal error"}'),
            new Response(502, [], '{"error":"bad gateway"}'),
            new Response(503, [], '{"error":"service unavailable"}'),
        ]);

        $provider = $this->createProvider($mock, maxRetries: 3);

        try {
            $provider->doRequest('POST', '/test');
            $this->fail('Expected AiProviderException to be thrown');
        } catch (AiProviderException $e) {
            $this->assertTrue($e->isRetryable());
            $this->assertEquals(503, $e->getHttpStatus());
        }
    }

    public function testExhaustsRetriesOn429(): void
    {
        $mock = new MockHandler([
            new Response(429, [], '{"error":"rate limited"}'),
            new Response(429, [], '{"error":"rate limited"}'),
            new Response(429, [], '{"error":"rate limited"}'),
        ]);

        $provider = $this->createProvider($mock, maxRetries: 3);

        try {
            $provider->doRequest('POST', '/test');
            $this->fail('Expected AiProviderException to be thrown');
        } catch (AiProviderException $e) {
            $this->assertTrue($e->isRetryable());
            $this->assertEquals(429, $e->getHttpStatus());
        }
    }

    public function test401DoesNotConsumeRetries(): void
    {
        // Only 1 response in the mock — if retry was attempted, Guzzle would throw
        // OutOfBoundsException. If it throws AiProviderException instead, no retry happened.
        $mock = new MockHandler([
            new Response(401, [], '{"error":"unauthorized"}'),
        ]);

        $provider = $this->createProvider($mock, maxRetries: 3);

        try {
            $provider->doRequest('POST', '/test');
            $this->fail('Expected AiProviderException to be thrown');
        } catch (AiProviderException $e) {
            $this->assertFalse($e->isRetryable());
            $this->assertEquals(401, $e->getHttpStatus());
        }
    }

    public function testNonJsonResponseThrowsException(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'not json'),
        ]);

        $provider = $this->createProvider($mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-JSON');

        $provider->doRequest('POST', '/test');
    }
}
