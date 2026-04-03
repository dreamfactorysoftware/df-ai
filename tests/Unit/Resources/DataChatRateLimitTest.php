<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Resources;

use DreamFactory\Core\AI\Providers\BaseAiProvider;
use DreamFactory\Core\AI\Resources\DataChatResource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Minimal provider stub used for loop-termination tests.
 *
 * Declared at file scope (not anonymous) to avoid the PHP fatal triggered by
 * the duplicate chatWithTools() declaration in AiProviderInterface when an
 * anonymous class implementation is processed.
 */
class StubProviderForDataChatTest extends BaseAiProvider
{
    public function __construct()
    {
        // Skip parent — we only need the interface contract satisfied.
    }

    public function complete(array $options): array
    {
        return ['content' => 'ok', 'provider' => 'stub', 'model' => 'stub',
                'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'stop'];
    }

    public function chat(array $messages, array $options = []): array
    {
        return ['content' => 'ok', 'provider' => 'stub', 'model' => 'stub',
                'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'stop'];
    }

    /** Returns no tool_calls so the agentic loop exits on the first iteration. */
    public function chatWithTools(array $messages, array $tools, array $options = []): array
    {
        return ['content' => 'answer', 'tool_calls' => null, 'finish_reason' => 'stop',
                'input_tokens' => 1, 'output_tokens' => 1, 'model' => 'stub', 'provider' => 'stub'];
    }

    public function listModels(): array { return []; }
    public function getProviderName(): string { return 'stub'; }
}

/**
 * Security regression tests for DataChatResource.
 *
 * Two concerns covered:
 *
 *   1. Rate limiter wired into handlePOST() — DataChat must not skip the
 *      RateLimiter::check() call that every other AI endpoint performs.
 *
 *   2. MAX_TOOL_ITERATIONS driven by config('ai.data_chat.max_iterations')
 *      rather than a lone hardcoded constant. The constant is now only the
 *      fallback when config is absent or zero.
 *
 * All assertions work against real class source/reflection so they catch
 * regressions without needing a full DreamFactory stack.
 */
class DataChatRateLimitTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Resources/DataChatResource.php';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Could not read DataChatResource.php at {$path}");
        $this->source = $contents;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fix #1 — RateLimiter::check() presence
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The RateLimiter class must be imported at the top of DataChatResource.
     */
    public function testRateLimiterImportPresent(): void
    {
        $this->assertStringContainsString(
            'use DreamFactory\Core\AI\Services\RateLimiter;',
            $this->source,
            'RateLimiter import is missing from DataChatResource.'
        );
    }

    /**
     * handlePOST() must contain a RateLimiter::check() call.
     */
    public function testHandlePostContainsRateLimiterCheck(): void
    {
        $handlePostStart = strpos($this->source, 'protected function handlePOST()');
        $this->assertNotFalse($handlePostStart, 'handlePOST() not found in DataChatResource.');

        $handlePostBody = substr($this->source, $handlePostStart);

        $this->assertStringContainsString(
            'RateLimiter::check(',
            $handlePostBody,
            'RateLimiter::check() call is missing from DataChatResource::handlePOST().'
        );
    }

    /**
     * The rate-limiter check must appear before the agentic loop is launched,
     * so a rate-limited caller never burns provider tokens.
     */
    public function testRateLimiterCallPrecedesAgenticLoop(): void
    {
        $handlePostStart = strpos($this->source, 'protected function handlePOST()');
        $this->assertNotFalse($handlePostStart, 'handlePOST() not found in DataChatResource.');

        $handlePostBody = substr($this->source, $handlePostStart);

        $rateLimiterPos = strpos($handlePostBody, 'RateLimiter::check(');
        $agenticLoopPos = strpos($handlePostBody, 'runAgenticLoop(');

        $this->assertNotFalse($rateLimiterPos, 'RateLimiter::check() not found in handlePOST() body.');
        $this->assertNotFalse($agenticLoopPos, 'runAgenticLoop() call not found in handlePOST() body.');

        $this->assertLessThan(
            $agenticLoopPos,
            $rateLimiterPos,
            'RateLimiter::check() must appear before runAgenticLoop() in handlePOST().'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fix #2 — MAX_TOOL_ITERATIONS driven by config
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The hardcoded MAX_TOOL_ITERATIONS = 25 constant must be gone.
     * Its presence would mean the constant is still the sole authority.
     */
    public function testHardcodedMaxToolIterationsConstantRemoved(): void
    {
        $ref = new ReflectionClass(DataChatResource::class);

        $this->assertFalse(
            $ref->hasConstant('MAX_TOOL_ITERATIONS'),
            'The old MAX_TOOL_ITERATIONS = 25 constant still exists. '
            . 'It must be replaced by DEFAULT_MAX_TOOL_ITERATIONS + config(\'ai.data_chat.max_iterations\').'
        );
    }

    /**
     * DEFAULT_MAX_TOOL_ITERATIONS must exist and equal 10 to match config/ai.php.
     */
    public function testDefaultMaxToolIterationsConstantIs10(): void
    {
        $ref   = new ReflectionClass(DataChatResource::class);
        $const = $ref->getReflectionConstant('DEFAULT_MAX_TOOL_ITERATIONS');

        $this->assertNotFalse(
            $const,
            'DEFAULT_MAX_TOOL_ITERATIONS constant is missing from DataChatResource.'
        );
        $this->assertSame(
            10,
            $const->getValue(),
            'DEFAULT_MAX_TOOL_ITERATIONS must be 10 to match the value in config/ai.php.'
        );
    }

    /**
     * runAgenticLoop() must read config('ai.data_chat.max_iterations') for the
     * iteration limit rather than a literal integer.
     */
    public function testMaxIterationsFromConfig(): void
    {
        $loopStart = strpos($this->source, 'private function runAgenticLoop(');
        $this->assertNotFalse($loopStart, 'runAgenticLoop() not found in DataChatResource.');

        $loopBody = substr($this->source, $loopStart);

        $this->assertStringContainsString(
            "config('ai.data_chat.max_iterations'",
            $loopBody,
            "runAgenticLoop() must call config('ai.data_chat.max_iterations') for the iteration limit."
        );
    }

    /**
     * When the config value is zero or negative the fallback constant must be used.
     * We verify this by checking that DEFAULT_MAX_TOOL_ITERATIONS appears at least
     * twice in the loop body — once as the config() default argument and once in
     * the guard expression.
     */
    public function testZeroConfigValueFallsBackToDefault(): void
    {
        $loopStart = strpos($this->source, 'private function runAgenticLoop(');
        $this->assertNotFalse($loopStart, 'runAgenticLoop() not found in DataChatResource.');

        $loopBody    = substr($this->source, $loopStart);
        $occurrences = substr_count($loopBody, 'DEFAULT_MAX_TOOL_ITERATIONS');

        $this->assertGreaterThanOrEqual(
            2,
            $occurrences,
            'DEFAULT_MAX_TOOL_ITERATIONS must appear at least twice in runAgenticLoop(): '
            . 'once as the config() default and once as the zero/negative-value guard.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Integration-style loop test (skips if config() helper is unavailable)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the provider returns no tool calls the loop must exit on iteration 1.
     *
     * This exercises runAgenticLoop() end-to-end using the named stub above.
     * If config() is not available (bare PHPUnit, no Laravel bootstrap) the test
     * is skipped rather than failing so CI doesn't block on environment differences.
     */
    public function testLoopExitsImmediatelyWhenNoToolCalls(): void
    {
        $ref      = new ReflectionClass(DataChatResource::class);
        $resource = $ref->newInstanceWithoutConstructor();

        $provider = new StubProviderForDataChatTest();

        $method = $ref->getMethod('runAgenticLoop');
        $method->setAccessible(true);

        try {
            $result = $method->invoke(
                $resource,
                $provider,
                [['role' => 'user', 'content' => 'hello']],
                [],                                    // tools
                ['client' => null, 'baseUrl' => ''],   // toolClient (unused when no tool calls)
                [],                                    // dbServices
                hrtime(true),
                [],                                    // payload
            );
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            // The Laravel Application container is not bootstrapped in this bare
            // PHPUnit run, so config() resolves but app('config') fails.
            // Mark skipped rather than failing — the source-inspection tests above
            // already provide meaningful coverage without a full stack.
            $this->markTestSkipped(
                'Laravel Application container not bootstrapped — '
                . 'run with Orchestra Testbench for full integration coverage.'
            );
        }

        $this->assertSame(
            1,
            (int) ($result['iterations'] ?? -1),
            'Loop must report 1 iteration when the provider returns no tool calls.'
        );
    }
}
