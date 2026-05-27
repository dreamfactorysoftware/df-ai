<?php

namespace DreamFactory\Core\AI\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: DataChatResource must rate-limit the agentic loop.
 *
 * The April 2026 audit (df-ai F-01) found that DataChatResource::handlePOST()
 * has no RateLimiter::check() call, even though the same package's
 * ChatResource and CompletionResource both gate every request through it.
 *
 * Each DataChat request can fan out into MAX_TOOL_ITERATIONS = 25 LLM calls
 * plus N tool executions, so an unauthenticated burst can cost 25× the LLM
 * tokens of a normal chat request and exhaust budget faster than any wallet
 * threshold can react.
 *
 * The fix wires RateLimiter::check() into handlePOST() before any provider
 * call, mirroring ChatResource line 183.
 */
class DataChatRateLimitingTest extends TestCase
{
    private string $sourcePath;
    private string $contents;

    protected function setUp(): void
    {
        $this->sourcePath = __DIR__ . '/../../src/Resources/DataChatResource.php';
        $this->assertFileExists($this->sourcePath);
        $this->contents = file_get_contents($this->sourcePath);
    }

    public function testDataChatResourceImportsRateLimiter(): void
    {
        $this->assertMatchesRegularExpression(
            '/use\s+DreamFactory\\\\Core\\\\AI\\\\Services\\\\RateLimiter\s*;/',
            $this->contents,
            'DataChatResource must import the RateLimiter service'
        );
    }

    public function testHandlePostCallsRateLimiterCheck(): void
    {
        $this->assertMatchesRegularExpression(
            '/RateLimiter::check\s*\(/',
            $this->contents,
            'DataChatResource must call RateLimiter::check() before running the agentic loop'
        );
    }

    public function testRateLimiterCalledBeforeAgenticLoop(): void
    {
        // The rate limit check must precede the runAgenticLoop() invocation,
        // otherwise an attacker can fan out 25 LLM calls before being throttled.
        $checkPos = strpos($this->contents, 'RateLimiter::check');
        $loopPos = strpos($this->contents, 'runAgenticLoop(');

        $this->assertNotFalse($checkPos, 'RateLimiter::check must appear in source');
        $this->assertNotFalse($loopPos, 'runAgenticLoop() invocation must appear in source');
        $this->assertLessThan($loopPos, $checkPos,
            'RateLimiter::check() must be called BEFORE runAgenticLoop() invocation'
        );
    }
}
