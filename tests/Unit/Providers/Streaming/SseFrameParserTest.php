<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Providers\Streaming;

use DreamFactory\Core\AI\Providers\Streaming\SseFrameParser;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SseFrameParser. Both the pure parseFrame() helper and the
 * stream-buffered parse() / parseNdjson() generators are exercised against
 * fixtures shaped after the actual Anthropic / OpenAI / Ollama wire output.
 *
 * The stream tests use Psr7\Utils::streamFor to wrap a string in a
 * StreamInterface — same shape Guzzle hands back from a real
 * `'stream' => true` request, so the tests cover the read-buffer behaviour
 * without needing a live HTTP connection.
 */
class SseFrameParserTest extends TestCase
{
    // ─── parseFrame (pure) ────────────────────────────────────────────────

    public function testParseFrameExtractsDataAndEvent(): void
    {
        $frame = "event: message\ndata: hello world";
        $this->assertSame(
            ['event' => 'message', 'data' => 'hello world'],
            SseFrameParser::parseFrame($frame)
        );
    }

    public function testParseFrameWithDataOnly(): void
    {
        // Most OpenAI/-compatible streams omit `event:` and emit only `data:`.
        $this->assertSame(
            ['event' => null, 'data' => '{"x":1}'],
            SseFrameParser::parseFrame('data: {"x":1}')
        );
    }

    public function testParseFrameJoinsMultipleDataLinesWithNewline(): void
    {
        // Per spec, multi-line data: lines are concatenated with \n.
        $frame = "data: line one\ndata: line two\ndata: line three";
        $this->assertSame(
            ['event' => null, 'data' => "line one\nline two\nline three"],
            SseFrameParser::parseFrame($frame)
        );
    }

    public function testParseFrameStripsExactlyOneLeadingSpaceFromValue(): void
    {
        // "data: x" → "x", "data:  x" → " x" (per WHATWG SSE spec).
        $this->assertSame(
            ['event' => null, 'data' => 'x'],
            SseFrameParser::parseFrame('data: x')
        );
        $this->assertSame(
            ['event' => null, 'data' => ' x'],
            SseFrameParser::parseFrame('data:  x')
        );
        // No space after colon either — preserve verbatim.
        $this->assertSame(
            ['event' => null, 'data' => 'x'],
            SseFrameParser::parseFrame('data:x')
        );
    }

    public function testParseFrameIgnoresCommentLines(): void
    {
        // ":heartbeat" is a comment used as keepalive by some providers.
        $frame = ": heartbeat\ndata: real payload";
        $this->assertSame(
            ['event' => null, 'data' => 'real payload'],
            SseFrameParser::parseFrame($frame)
        );
    }

    public function testParseFrameIgnoresUnknownFields(): void
    {
        // id: and retry: aren't relevant for AI streams; unknown fields too.
        $frame = "id: abc123\nevent: chunk\nretry: 5000\nfoo: bar\ndata: payload";
        $this->assertSame(
            ['event' => 'chunk', 'data' => 'payload'],
            SseFrameParser::parseFrame($frame)
        );
    }

    public function testParseFrameReturnsNullWhenNoDataField(): void
    {
        // A frame with only event: or only comments → no payload, drop it.
        $this->assertNull(SseFrameParser::parseFrame("event: heartbeat"));
        $this->assertNull(SseFrameParser::parseFrame(": comment-only"));
        $this->assertNull(SseFrameParser::parseFrame(""));
    }

    public function testParseFrameHandlesBareFieldWithoutColon(): void
    {
        // "data" with no colon is a legal (if unusual) form — empty value.
        // Don't crash; emit empty-string data.
        $this->assertSame(
            ['event' => null, 'data' => ''],
            SseFrameParser::parseFrame('data')
        );
    }

    // ─── parse (stream-buffered) ──────────────────────────────────────────

    public function testParseYieldsFramesFromOpenAiStyleStream(): void
    {
        // Mimic an OpenAI /v1/chat/completions stream: each frame is one
        // chat.completion.chunk JSON wrapped in `data:` + blank line.
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
             . "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n"
             . "data: [DONE]\n\n";

        $frames = iterator_to_array(SseFrameParser::parse(Utils::streamFor($sse)), false);

        $this->assertCount(3, $frames);
        $this->assertSame('{"choices":[{"delta":{"content":"Hello"}}]}', $frames[0]['data']);
        $this->assertSame('{"choices":[{"delta":{"content":" world"}}]}', $frames[1]['data']);
        $this->assertSame('[DONE]', $frames[2]['data']);
        foreach ($frames as $f) {
            $this->assertNull($f['event'], 'OpenAI streams omit event:');
        }
    }

    public function testParseYieldsFramesFromAnthropicStyleStream(): void
    {
        // Anthropic /v1/messages stream: every frame has both event: and
        // data:, with named events (message_start, content_block_delta, etc.).
        $sse = "event: message_start\ndata: {\"type\":\"message_start\"}\n\n"
             . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"text\":\"hi\"}}\n\n"
             . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";

        $frames = iterator_to_array(SseFrameParser::parse(Utils::streamFor($sse)), false);

        $this->assertCount(3, $frames);
        $this->assertSame('message_start', $frames[0]['event']);
        $this->assertSame('content_block_delta', $frames[1]['event']);
        $this->assertSame('message_stop', $frames[2]['event']);
    }

    public function testParseHandlesCrlfLineEndings(): void
    {
        // Some HTTP intermediaries emit CRLF — must produce identical frames.
        $sse = "event: a\r\ndata: 1\r\n\r\nevent: b\r\ndata: 2\r\n\r\n";
        $frames = iterator_to_array(SseFrameParser::parse(Utils::streamFor($sse)), false);
        $this->assertCount(2, $frames);
        $this->assertSame(['event' => 'a', 'data' => '1'], $frames[0]);
        $this->assertSame(['event' => 'b', 'data' => '2'], $frames[1]);
    }

    public function testParseHandlesPartialFrameAcrossReadBoundary(): void
    {
        // The Guzzle stream may return a chunk that splits a frame mid-data.
        // The parser must buffer and reassemble. We force this by using a
        // chunkSize smaller than any single frame.
        $sse = "data: " . str_repeat('x', 1000) . "\n\n"
             . "data: " . str_repeat('y', 500) . "\n\n";

        $frames = iterator_to_array(
            SseFrameParser::parse(Utils::streamFor($sse), chunkSize: 64),
            false
        );

        $this->assertCount(2, $frames);
        $this->assertSame(str_repeat('x', 1000), $frames[0]['data']);
        $this->assertSame(str_repeat('y', 500), $frames[1]['data']);
    }

    public function testParseEmitsTrailingFrameWithoutBlankLine(): void
    {
        // Some servers terminate after `data: [DONE]` with no trailing \n\n.
        // Don't lose the final frame.
        $sse = "data: hello\n\ndata: [DONE]";
        $frames = iterator_to_array(SseFrameParser::parse(Utils::streamFor($sse)), false);
        $this->assertCount(2, $frames);
        $this->assertSame('[DONE]', $frames[1]['data']);
    }

    public function testParseDropsFramesWithoutDataField(): void
    {
        // Comment-only and event-only frames carry no payload — must not
        // surface to the caller, otherwise downstream chunk handlers crash.
        $sse = ": ping\n\nevent: heartbeat\n\ndata: real\n\n";
        $frames = iterator_to_array(SseFrameParser::parse(Utils::streamFor($sse)), false);
        $this->assertCount(1, $frames);
        $this->assertSame('real', $frames[0]['data']);
    }

    public function testParseEmptyStreamYieldsNothing(): void
    {
        $frames = iterator_to_array(SseFrameParser::parse(Utils::streamFor('')), false);
        $this->assertSame([], $frames);
    }

    // ─── parseNdjson (Ollama) ─────────────────────────────────────────────

    public function testParseNdjsonYieldsOneObjectPerLine(): void
    {
        // Mimic Ollama's /api/chat streaming output.
        $ndjson = '{"message":{"content":"hi"},"done":false}' . "\n"
                . '{"message":{"content":" there"},"done":false}' . "\n"
                . '{"done":true,"prompt_eval_count":4,"eval_count":7}' . "\n";

        $rows = iterator_to_array(SseFrameParser::parseNdjson(Utils::streamFor($ndjson)), false);

        $this->assertCount(3, $rows);
        $this->assertSame('hi', $rows[0]['message']['content']);
        $this->assertSame(' there', $rows[1]['message']['content']);
        $this->assertTrue($rows[2]['done']);
        $this->assertSame(4, $rows[2]['prompt_eval_count']);
    }

    public function testParseNdjsonSkipsMalformedLines(): void
    {
        // Ollama has historically printed warnings to the stream — must not
        // kill the iterator. One bad line; everything else still yields.
        $ndjson = '{"ok":1}' . "\n"
                . 'this is not json at all' . "\n"
                . '{"ok":2}' . "\n";

        $rows = iterator_to_array(SseFrameParser::parseNdjson(Utils::streamFor($ndjson)), false);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['ok']);
        $this->assertSame(2, $rows[1]['ok']);
    }

    public function testParseNdjsonEmitsTrailingLineWithoutNewline(): void
    {
        // If the stream ends without a final \n, don't drop the last record.
        $ndjson = '{"a":1}' . "\n" . '{"b":2}';
        $rows = iterator_to_array(SseFrameParser::parseNdjson(Utils::streamFor($ndjson)), false);
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[1]['b']);
    }

    public function testParseNdjsonHandlesPartialReadBoundary(): void
    {
        $ndjson = '{"id":"' . str_repeat('a', 500) . '","ok":1}' . "\n"
                . '{"id":"' . str_repeat('b', 500) . '","ok":2}' . "\n";

        $rows = iterator_to_array(
            SseFrameParser::parseNdjson(Utils::streamFor($ndjson), chunkSize: 32),
            false
        );

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['ok']);
        $this->assertSame(2, $rows[1]['ok']);
    }

    public function testParseNdjsonSkipsBlankLines(): void
    {
        $ndjson = '{"a":1}' . "\n\n" . '{"b":2}' . "\n";
        $rows = iterator_to_array(SseFrameParser::parseNdjson(Utils::streamFor($ndjson)), false);
        $this->assertCount(2, $rows);
    }
}
