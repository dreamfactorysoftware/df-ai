<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Providers\Streaming;

use DreamFactory\Core\AI\Providers\AnthropicProvider;
use DreamFactory\Core\AI\Providers\OllamaProvider;
use DreamFactory\Core\AI\Providers\OpenAICompatibleProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the per-provider stream translators that map a generator of
 * raw SSE/NDJSON frames into the unified event shape:
 *
 *   ['type' => 'delta',  'text' => string]
 *   ['type' => 'usage',  'input_tokens' => int, 'output_tokens' => int]
 *   ['type' => 'finish', 'reason' => string]
 *   ['type' => 'done']
 *   ['type' => 'error',  'message' => string]
 *
 * The fixtures here are shaped after real Anthropic / OpenAI / Ollama
 * stream output. Together they pin down the wire contract — a refactor
 * that drops a delta or mis-orders the terminal sentinels would silently
 * break billing and chat UIs across all three providers.
 */
class StreamTranslatorTest extends TestCase
{
    // ─── OpenAI / OpenAI-compatible ───────────────────────────────────────

    public function testOpenAiStreamYieldsDeltasUsageFinishDone(): void
    {
        // Realistic OpenAI /v1/chat/completions stream: two content deltas,
        // a finish_reason chunk, a usage chunk (when stream_options.include_usage),
        // and a [DONE] sentinel.
        $frames = [
            ['event' => null, 'data' => '{"choices":[{"delta":{"content":"Hello"},"finish_reason":null}]}'],
            ['event' => null, 'data' => '{"choices":[{"delta":{"content":" world"},"finish_reason":null}]}'],
            ['event' => null, 'data' => '{"choices":[{"delta":{},"finish_reason":"stop"}]}'],
            ['event' => null, 'data' => '{"choices":[],"usage":{"prompt_tokens":10,"completion_tokens":5}}'],
            ['event' => null, 'data' => '[DONE]'],
        ];

        $events = iterator_to_array(
            OpenAICompatibleProvider::translateOpenAiStream($frames, 'openai', 'gpt-4o'),
            false
        );

        $this->assertSame(['type' => 'delta', 'text' => 'Hello'], $events[0]);
        $this->assertSame(['type' => 'delta', 'text' => ' world'], $events[1]);
        $this->assertSame(['type' => 'usage', 'input_tokens' => 10, 'output_tokens' => 5], $events[2]);
        $this->assertSame(['type' => 'finish', 'reason' => 'stop'], $events[3]);
        $this->assertSame(['type' => 'done'], $events[4]);
    }

    public function testOpenAiStreamSkipsEmptyContentDeltas(): void
    {
        // The first chunk in an OpenAI stream often has only the role
        // assignment and an empty content — must not yield empty deltas to
        // the client.
        $frames = [
            ['event' => null, 'data' => '{"choices":[{"delta":{"role":"assistant","content":""}}]}'],
            ['event' => null, 'data' => '{"choices":[{"delta":{"content":"Hi"}}]}'],
            ['event' => null, 'data' => '[DONE]'],
        ];

        $events = iterator_to_array(
            OpenAICompatibleProvider::translateOpenAiStream($frames, 'openai', 'gpt-4o'),
            false
        );

        // Only one delta event (the non-empty one), then done.
        $deltas = array_filter($events, fn($e) => $e['type'] === 'delta');
        $this->assertCount(1, $deltas);
        $this->assertSame('Hi', array_values($deltas)[0]['text']);
    }

    public function testOpenAiStreamHandlesMidStreamErrorChunk(): void
    {
        // OpenAI rarely emits an error mid-stream, but when it does, we must
        // surface it and stop iterating (don't keep yielding usage/done).
        $frames = [
            ['event' => null, 'data' => '{"choices":[{"delta":{"content":"foo"}}]}'],
            ['event' => null, 'data' => '{"error":{"message":"context_length_exceeded"}}'],
            // Anything after the error is unreachable — but include a [DONE]
            // to make sure we don't accidentally process it.
            ['event' => null, 'data' => '[DONE]'],
        ];

        $events = iterator_to_array(
            OpenAICompatibleProvider::translateOpenAiStream($frames, 'openai', 'gpt-4o'),
            false
        );

        $this->assertCount(2, $events);
        $this->assertSame('delta', $events[0]['type']);
        $this->assertSame('error', $events[1]['type']);
        $this->assertStringContainsString('context_length_exceeded', $events[1]['message']);
    }

    public function testOpenAiStreamSkipsMalformedChunks(): void
    {
        $frames = [
            ['event' => null, 'data' => 'not-json-at-all'],
            ['event' => null, 'data' => '{"choices":[{"delta":{"content":"ok"}}]}'],
            ['event' => null, 'data' => '[DONE]'],
        ];

        $events = iterator_to_array(
            OpenAICompatibleProvider::translateOpenAiStream($frames, 'openai', 'gpt-4o'),
            false
        );

        // The malformed chunk is skipped silently.
        $this->assertSame('delta', $events[0]['type']);
        $this->assertSame('ok', $events[0]['text']);
    }

    public function testOpenAiStreamWithoutDoneStillEmitsTerminalSentinels(): void
    {
        // Some compat servers (lm-studio, llama.cpp) terminate without [DONE].
        // We MUST still emit done so billing fires.
        $frames = [
            ['event' => null, 'data' => '{"choices":[{"delta":{"content":"hi"},"finish_reason":"stop"}]}'],
        ];

        $events = iterator_to_array(
            OpenAICompatibleProvider::translateOpenAiStream($frames, 'openai_compatible', 'qwen'),
            false
        );

        $types = array_column($events, 'type');
        $this->assertContains('delta', $types);
        $this->assertContains('finish', $types);
        $this->assertContains('done', $types);
        $this->assertSame('done', end($events)['type']);
    }

    public function testOpenAiStreamFinishReasonOrderedAfterDeltas(): void
    {
        // The contract is delta* → usage? → finish → done. A finish_reason
        // chunk that arrives BEFORE [DONE] should still be emitted in order.
        $frames = [
            ['event' => null, 'data' => '{"choices":[{"delta":{"content":"a"}}]}'],
            ['event' => null, 'data' => '{"choices":[{"delta":{"content":"b"},"finish_reason":"length"}]}'],
            ['event' => null, 'data' => '[DONE]'],
        ];

        $events = iterator_to_array(
            OpenAICompatibleProvider::translateOpenAiStream($frames, 'openai', 'gpt-4o'),
            false
        );

        $types = array_column($events, 'type');
        $this->assertSame(['delta', 'delta', 'finish', 'done'], $types);
        $this->assertSame('length', $events[2]['reason']);
    }

    // ─── Anthropic ────────────────────────────────────────────────────────

    public function testAnthropicStreamYieldsDeltasAndFinalUsage(): void
    {
        // Realistic Anthropic /v1/messages stream — message_start with input
        // tokens, two content_block_deltas, message_delta with stop reason
        // and final output token count, then message_stop.
        $frames = [
            ['event' => 'message_start', 'data' => '{"type":"message_start","message":{"id":"msg_1","model":"claude-sonnet-4-5","usage":{"input_tokens":42,"output_tokens":1}}}'],
            ['event' => 'content_block_start', 'data' => '{"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}'],
            ['event' => 'content_block_delta', 'data' => '{"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}'],
            ['event' => 'content_block_delta', 'data' => '{"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" world"}}'],
            ['event' => 'content_block_stop', 'data' => '{"type":"content_block_stop","index":0}'],
            ['event' => 'message_delta', 'data' => '{"type":"message_delta","delta":{"stop_reason":"end_turn","stop_sequence":null},"usage":{"output_tokens":17}}'],
            ['event' => 'message_stop', 'data' => '{"type":"message_stop"}'],
        ];

        $events = iterator_to_array(AnthropicProvider::translateAnthropicStream($frames), false);

        $this->assertSame(['type' => 'delta', 'text' => 'Hello'], $events[0]);
        $this->assertSame(['type' => 'delta', 'text' => ' world'], $events[1]);
        $this->assertSame(['type' => 'usage', 'input_tokens' => 42, 'output_tokens' => 17], $events[2]);
        $this->assertSame(['type' => 'finish', 'reason' => 'end_turn'], $events[3]);
        $this->assertSame(['type' => 'done'], $events[4]);
    }

    public function testAnthropicStreamIgnoresPingFrames(): void
    {
        $frames = [
            ['event' => 'ping', 'data' => '{}'],
            ['event' => 'message_start', 'data' => '{"type":"message_start","message":{"usage":{"input_tokens":5,"output_tokens":1}}}'],
            ['event' => 'ping', 'data' => '{}'],
            ['event' => 'content_block_delta', 'data' => '{"delta":{"type":"text_delta","text":"yo"}}'],
            ['event' => 'ping', 'data' => '{}'],
            ['event' => 'message_delta', 'data' => '{"delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":1}}'],
            ['event' => 'message_stop', 'data' => '{}'],
        ];

        $events = iterator_to_array(AnthropicProvider::translateAnthropicStream($frames), false);

        // ping doesn't appear in the unified stream.
        $types = array_column($events, 'type');
        $this->assertNotContains('ping', $types);
        $this->assertSame(['delta', 'usage', 'finish', 'done'], $types);
    }

    public function testAnthropicStreamIgnoresNonTextDeltas(): void
    {
        // input_json_delta (tool-use partial JSON) should NOT surface as a
        // text delta — chatStream() is text-only.
        $frames = [
            ['event' => 'message_start', 'data' => '{"type":"message_start","message":{"usage":{"input_tokens":1,"output_tokens":1}}}'],
            ['event' => 'content_block_delta', 'data' => '{"delta":{"type":"input_json_delta","partial_json":"{\"a"}}'],
            ['event' => 'content_block_delta', 'data' => '{"delta":{"type":"text_delta","text":"hi"}}'],
            ['event' => 'message_delta', 'data' => '{"delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":1}}'],
            ['event' => 'message_stop', 'data' => '{}'],
        ];

        $events = iterator_to_array(AnthropicProvider::translateAnthropicStream($frames), false);

        $deltas = array_values(array_filter($events, fn($e) => $e['type'] === 'delta'));
        $this->assertCount(1, $deltas);
        $this->assertSame('hi', $deltas[0]['text']);
    }

    public function testAnthropicStreamSurfacesErrorEvent(): void
    {
        $frames = [
            ['event' => 'message_start', 'data' => '{"type":"message_start","message":{"usage":{"input_tokens":1,"output_tokens":1}}}'],
            ['event' => 'error', 'data' => '{"type":"error","error":{"type":"overloaded_error","message":"Anthropic is currently overloaded"}}'],
            // After an error, we must NOT continue to message_stop.
            ['event' => 'message_stop', 'data' => '{}'],
        ];

        $events = iterator_to_array(AnthropicProvider::translateAnthropicStream($frames), false);

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['type']);
        $this->assertStringContainsString('overloaded', $events[0]['message']);
    }

    public function testAnthropicStreamWithoutMessageStopStillFinalizes(): void
    {
        // If the upstream connection drops before message_stop, emit usage +
        // done with whatever counts we accumulated.
        $frames = [
            ['event' => 'message_start', 'data' => '{"type":"message_start","message":{"usage":{"input_tokens":50,"output_tokens":1}}}'],
            ['event' => 'content_block_delta', 'data' => '{"delta":{"type":"text_delta","text":"partial"}}'],
        ];

        $events = iterator_to_array(AnthropicProvider::translateAnthropicStream($frames), false);

        $types = array_column($events, 'type');
        $this->assertContains('delta', $types);
        $this->assertContains('usage', $types);
        $this->assertContains('done', $types);
        // Output token count was never delivered; should be 0, not crash.
        $usage = array_values(array_filter($events, fn($e) => $e['type'] === 'usage'))[0];
        $this->assertSame(50, $usage['input_tokens']);
        $this->assertSame(0, $usage['output_tokens']);
    }

    // ─── Ollama (NDJSON) ──────────────────────────────────────────────────

    public function testOllamaStreamYieldsDeltasUsageFinishDone(): void
    {
        $rows = [
            ['model' => 'qwen', 'message' => ['role' => 'assistant', 'content' => 'Hi'], 'done' => false],
            ['model' => 'qwen', 'message' => ['role' => 'assistant', 'content' => ' there'], 'done' => false],
            ['model' => 'qwen', 'done' => true, 'prompt_eval_count' => 4, 'eval_count' => 7, 'done_reason' => 'stop'],
        ];

        $events = iterator_to_array(OllamaProvider::translateOllamaStream($rows), false);

        $this->assertSame(['type' => 'delta', 'text' => 'Hi'], $events[0]);
        $this->assertSame(['type' => 'delta', 'text' => ' there'], $events[1]);
        $this->assertSame(['type' => 'usage', 'input_tokens' => 4, 'output_tokens' => 7], $events[2]);
        $this->assertSame(['type' => 'finish', 'reason' => 'stop'], $events[3]);
        $this->assertSame(['type' => 'done'], $events[4]);
    }

    public function testOllamaStreamSkipsEmptyContent(): void
    {
        $rows = [
            ['message' => ['content' => ''], 'done' => false],
            ['message' => ['content' => 'real'], 'done' => false],
            ['done' => true, 'prompt_eval_count' => 1, 'eval_count' => 1],
        ];

        $events = iterator_to_array(OllamaProvider::translateOllamaStream($rows), false);
        $deltas = array_values(array_filter($events, fn($e) => $e['type'] === 'delta'));
        $this->assertCount(1, $deltas);
        $this->assertSame('real', $deltas[0]['text']);
    }

    public function testOllamaStreamSurfacesError(): void
    {
        $rows = [
            ['error' => 'model "qwen-fake" not found, try pulling it first'],
        ];

        $events = iterator_to_array(OllamaProvider::translateOllamaStream($rows), false);

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['type']);
        $this->assertStringContainsString('not found', $events[0]['message']);
    }

    public function testOllamaStreamDefaultsFinishReasonToStop(): void
    {
        // Older Ollama versions don't emit done_reason — default to "stop".
        $rows = [
            ['message' => ['content' => 'hi'], 'done' => false],
            ['done' => true, 'prompt_eval_count' => 1, 'eval_count' => 1],
        ];

        $events = iterator_to_array(OllamaProvider::translateOllamaStream($rows), false);
        $finish = array_values(array_filter($events, fn($e) => $e['type'] === 'finish'))[0];
        $this->assertSame('stop', $finish['reason']);
    }

    public function testOllamaStreamWithoutDoneFrameStillFinalizes(): void
    {
        // Daemon dies mid-stream — emit done so billing fires.
        $rows = [
            ['message' => ['content' => 'hi'], 'done' => false],
        ];

        $events = iterator_to_array(OllamaProvider::translateOllamaStream($rows), false);
        $types = array_column($events, 'type');
        $this->assertContains('delta', $types);
        $this->assertContains('done', $types);
    }
}
