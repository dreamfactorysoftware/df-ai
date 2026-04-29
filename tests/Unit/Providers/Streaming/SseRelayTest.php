<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Providers\Streaming;

use DreamFactory\Core\AI\Providers\Streaming\SseRelay;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SseRelay — the pure-PHP layer that turns unified provider
 * events into OpenAI-shape SSE frames AND drives the stream loop.
 *
 * The renderer tests pin down the exact wire shape (so existing OpenAI
 * SDK clients don't need adapters). The drive() tests exercise the
 * try/finally billing contract: success → 'success', client-abort →
 * 'partial', error event → 'error', exception → 'error'. Misbehaviour
 * here is the worst class of billing bug the gateway can ship, so the
 * coverage here is intentionally exhaustive.
 */
class SseRelayTest extends TestCase
{
    // ─── renderFrame ──────────────────────────────────────────────────────

    public function testRenderDeltaProducesOpenAiChunkShape(): void
    {
        $out = SseRelay::renderFrame(
            ['type' => 'delta', 'text' => 'Hello'],
            'anthropic',
            'claude-sonnet-4-5',
            'chatcmpl-test123',
        );

        $this->assertNotNull($out);
        $this->assertStringStartsWith('data: ', $out);
        $this->assertStringEndsWith("\n\n", $out);

        $payload = json_decode(substr(trim($out), 6), true);
        $this->assertSame('chatcmpl-test123', $payload['id']);
        $this->assertSame('chat.completion.chunk', $payload['object']);
        $this->assertSame('claude-sonnet-4-5', $payload['model']);
        $this->assertSame('anthropic', $payload['provider']);
        $this->assertSame('Hello', $payload['choices'][0]['delta']['content']);
        $this->assertNull($payload['choices'][0]['finish_reason']);
    }

    public function testRenderDoneEmitsLiteralBracketDone(): void
    {
        $out = SseRelay::renderFrame(['type' => 'done'], 'openai', 'gpt-4o', 'cid');
        $this->assertSame("data: [DONE]\n\n", $out);
    }

    public function testRenderFinishProducesStopReasonChunk(): void
    {
        $out = SseRelay::renderFrame(
            ['type' => 'finish', 'reason' => 'end_turn'],
            'anthropic',
            'claude-sonnet-4-5',
            'cid',
        );

        $payload = json_decode(substr(trim($out), 6), true);
        $this->assertSame('end_turn', $payload['choices'][0]['finish_reason']);
        // Empty delta object on finish chunks — OpenAI SDKs expect this
        // shape (an empty object, not null/missing).
        $this->assertIsArray($payload['choices']);
    }

    public function testRenderUsageProducesUsageChunk(): void
    {
        $out = SseRelay::renderFrame(
            ['type' => 'usage', 'input_tokens' => 50, 'output_tokens' => 17],
            'anthropic',
            'claude',
            'cid',
        );

        $payload = json_decode(substr(trim($out), 6), true);
        $this->assertSame([], $payload['choices']);
        $this->assertSame(50, $payload['usage']['prompt_tokens']);
        $this->assertSame(17, $payload['usage']['completion_tokens']);
        $this->assertSame(67, $payload['usage']['total_tokens']);
    }

    public function testRenderErrorEmitsErrorEnvelope(): void
    {
        $out = SseRelay::renderFrame(
            ['type' => 'error', 'message' => 'context_length_exceeded'],
            'openai',
            'gpt-4o',
            'cid',
        );

        $payload = json_decode(substr(trim($out), 6), true);
        $this->assertSame('context_length_exceeded', $payload['error']['message']);
    }

    public function testRenderUnknownEventReturnsNull(): void
    {
        // A Phase-B event type (e.g. tool_call_delta) we haven't wired yet
        // shouldn't crash — just no wire output.
        $this->assertNull(SseRelay::renderFrame(['type' => 'tool_call_delta'], 'a', 'b', 'c'));
        $this->assertNull(SseRelay::renderFrame(['type' => 'mystery'], 'a', 'b', 'c'));
    }

    public function testRenderPreservesUnicodeAndSlashes(): void
    {
        // No escaping of unicode / slashes — clients want the model output
        // verbatim. Tests JSON_UNESCAPED_* flags are applied.
        $out = SseRelay::renderFrame(
            ['type' => 'delta', 'text' => "café 你好 https://example.com/x"],
            'p',
            'm',
            'cid',
        );
        $this->assertStringContainsString('café', $out);
        $this->assertStringContainsString('你好', $out);
        $this->assertStringContainsString('https://example.com/x', $out);
        // No double-escaping of forward slashes.
        $this->assertStringNotContainsString('https:\\/\\/example', $out);
    }

    // ─── drive ────────────────────────────────────────────────────────────

    public function testDriveSuccessPathReturnsSuccessAndCounts(): void
    {
        $events = (function () {
            yield ['type' => 'delta', 'text' => 'hi'];
            yield ['type' => 'usage', 'input_tokens' => 10, 'output_tokens' => 5];
            yield ['type' => 'finish', 'reason' => 'stop'];
            yield ['type' => 'done'];
        })();

        $sunk = [];
        $result = SseRelay::drive(
            $events,
            function (string $frame) use (&$sunk): bool { $sunk[] = $frame; return true; },
            'openai',
            'gpt-4o',
            'cid',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(10, $result['input_tokens']);
        $this->assertSame(5, $result['output_tokens']);
        $this->assertSame('stop', $result['finish_reason']);
        $this->assertNull($result['error_message']);
        // Each unified event with a wire form produced one SSE frame.
        $this->assertCount(4, $sunk);
        $this->assertStringContainsString('"hi"', $sunk[0]);
        $this->assertSame("data: [DONE]\n\n", $sunk[3]);
    }

    public function testDrivePartialOnClientAbort(): void
    {
        // Simulate the sink reporting connection_aborted() after the first
        // delta. Drive must stop iterating, return 'partial', AND have
        // captured whatever counts were already seen.
        $events = (function () {
            yield ['type' => 'usage', 'input_tokens' => 42, 'output_tokens' => 3];
            yield ['type' => 'delta', 'text' => 'first'];
            // Drive should never reach these — assertion below.
            $this->fail('should not pull past the aborted sink');
            yield ['type' => 'delta', 'text' => 'second'];
            yield ['type' => 'done'];
        })();

        $callCount = 0;
        $result = SseRelay::drive(
            $events,
            function () use (&$callCount): bool {
                $callCount++;
                // Abort after the second frame (usage + first delta).
                return $callCount < 2;
            },
            'openai',
            'gpt-4o',
            'cid',
        );

        $this->assertSame('partial', $result['status']);
        $this->assertSame(42, $result['input_tokens']);
        $this->assertSame(3, $result['output_tokens']);
        $this->assertNull($result['finish_reason']);
        $this->assertNull($result['error_message']);
    }

    public function testDriveReturnsPartialWhenStreamEndsWithoutDone(): void
    {
        // No `done` event emitted by the provider — status defaults to
        // 'partial' so a flaky upstream isn't billed as 'success'.
        $events = (function () {
            yield ['type' => 'delta', 'text' => 'hi'];
            yield ['type' => 'usage', 'input_tokens' => 5, 'output_tokens' => 2];
        })();

        $result = SseRelay::drive(
            $events,
            fn(string $f): bool => true,
            'openai',
            'gpt-4o',
            'cid',
        );

        $this->assertSame('partial', $result['status']);
        $this->assertSame(5, $result['input_tokens']);
        $this->assertSame(2, $result['output_tokens']);
    }

    public function testDriveErrorEventResultsInErrorStatus(): void
    {
        $events = (function () {
            yield ['type' => 'usage', 'input_tokens' => 5, 'output_tokens' => 0];
            yield ['type' => 'error', 'message' => 'overloaded'];
            yield ['type' => 'done'];
        })();

        $result = SseRelay::drive(
            $events,
            fn(string $f): bool => true,
            'anthropic',
            'claude',
            'cid',
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame('overloaded', $result['error_message']);
        // Token counts are still preserved (we may have prompt tokens).
        $this->assertSame(5, $result['input_tokens']);
    }

    public function testDriveErrorTakesPrecedenceOverDone(): void
    {
        // If both error and done arrive, status must be 'error', not 'success'.
        $events = (function () {
            yield ['type' => 'error', 'message' => 'bad'];
            yield ['type' => 'done'];
        })();

        $result = SseRelay::drive(
            $events,
            fn(string $f): bool => true,
            'p',
            'm',
            'cid',
        );

        $this->assertSame('error', $result['status']);
    }

    public function testDriveCatchesProviderException(): void
    {
        $events = (function () {
            yield ['type' => 'delta', 'text' => 'so far so good'];
            throw new \RuntimeException('upstream blew up');
            yield ['type' => 'done']; // unreachable
        })();

        $result = SseRelay::drive(
            $events,
            fn(string $f): bool => true,
            'openai',
            'gpt-4o',
            'cid',
        );

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('upstream blew up', $result['error_message']);
    }

    public function testDriveSkipsUnrenderableEvents(): void
    {
        // Events with no wire representation (e.g. unknown type) shouldn't
        // call the sink — but should still flow through for status tracking.
        $events = (function () {
            yield ['type' => 'mystery'];
            yield ['type' => 'delta', 'text' => 'real'];
            yield ['type' => 'done'];
        })();

        $sunkCount = 0;
        $result = SseRelay::drive(
            $events,
            function () use (&$sunkCount): bool { $sunkCount++; return true; },
            'p',
            'm',
            'cid',
        );

        // delta + done = 2 frames; the unrenderable mystery event was skipped.
        $this->assertSame(2, $sunkCount);
        $this->assertSame('success', $result['status']);
    }
}
