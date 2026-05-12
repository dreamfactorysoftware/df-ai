<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers\Streaming;

use Psr\Http\Message\StreamInterface;

/**
 * Parses SSE (Server-Sent Events) and NDJSON streams from the AI providers
 * into PHP generators of decoded frames. Pure-PHP, no external dependencies
 * beyond PSR-7 (which Guzzle gives us).
 *
 * SSE wire format (per WHATWG):
 *   field: value\n
 *   field: value\n
 *   \n            ← blank line ends a frame
 *
 * We handle:
 *   - `event:`  — assigned to the frame's event name
 *   - `data:`   — accumulates per spec; multi-line data is joined with \n
 *   - `:`       — comments (ignored, used as keepalives by some servers)
 *   - `id:`/`retry:`/unknown fields — ignored (not relevant to AI providers)
 *
 * Anthropic and OpenAI both emit standard SSE on their /v1/messages and
 * /v1/chat/completions endpoints when `stream: true` is set. Ollama uses
 * NDJSON instead — see {@see parseNdjson()}.
 *
 * The two methods that take a StreamInterface buffer chunked reads internally
 * so partial frames split across Guzzle reads are reassembled correctly.
 */
class SseFrameParser
{
    /**
     * Yield SSE frames from a Guzzle stream as they arrive.
     *
     * Each yielded array has keys:
     *   - `event`: ?string (null if no `event:` line was present)
     *   - `data`:  string  (multi-line data joined with \n; never null)
     *
     * Frames with no `data:` field are dropped silently — keepalive comments
     * (`: ping`) and id-only frames don't carry payload, so they shouldn't
     * trigger downstream chunk handlers.
     *
     * @return \Generator<int, array{event: ?string, data: string}>
     */
    public static function parse(StreamInterface $stream, int $chunkSize = 8192): \Generator
    {
        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read($chunkSize);
            if ($chunk === '') {
                // PSR-7 streams may return '' before EOF on slow upstreams.
                continue;
            }
            // Normalize CRLF → LF up front so frame splitting works on a
            // single delimiter regardless of what the server sent.
            $buffer .= str_replace("\r\n", "\n", $chunk);

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $parsed = self::parseFrame($frame);
                if ($parsed !== null) {
                    yield $parsed;
                }
            }
        }

        // Final buffered fragment (a frame not followed by a trailing blank
        // line). Most well-behaved servers emit the trailing \n\n, but some
        // OpenAI-compat backends terminate abruptly after `[DONE]`.
        if (trim($buffer) !== '') {
            $parsed = self::parseFrame($buffer);
            if ($parsed !== null) {
                yield $parsed;
            }
        }
    }

    /**
     * Parse a single complete SSE frame body (without the trailing blank
     * line) into the {event, data} shape, or null if the frame carried no
     * `data:` field. Pure — exposed for unit testing.
     *
     * @return array{event: ?string, data: string}|null
     */
    public static function parseFrame(string $frame): ?array
    {
        $event = null;
        $dataLines = [];

        foreach (explode("\n", $frame) as $line) {
            // Empty lines should not occur inside a frame post-split, but
            // tolerate trailing whitespace artifacts gracefully.
            if ($line === '') {
                continue;
            }
            // Comment lines (used as keepalives by some servers).
            if ($line[0] === ':') {
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                // A bare field with no value is legal per spec — treated as
                // an empty value. AI providers don't emit this shape, but
                // don't crash on it either.
                $field = $line;
                $value = '';
            } else {
                $field = substr($line, 0, $colon);
                $value = substr($line, $colon + 1);
                // Per spec, a single leading space after the colon is
                // stripped: "data: hello" → "hello", but "data:  hello" → " hello".
                if ($value !== '' && $value[0] === ' ') {
                    $value = substr($value, 1);
                }
            }

            if ($field === 'event') {
                $event = $value;
            } elseif ($field === 'data') {
                $dataLines[] = $value;
            }
            // id:, retry:, unknown fields → silently ignored.
        }

        if (empty($dataLines)) {
            return null;
        }

        return [
            'event' => $event,
            'data'  => implode("\n", $dataLines),
        ];
    }

    /**
     * Yield decoded JSON objects from an NDJSON (newline-delimited JSON)
     * stream. Used by Ollama, which streams one JSON object per line rather
     * than SSE frames.
     *
     * Lines that don't decode to an array are skipped silently so a single
     * malformed line doesn't kill the stream — Ollama has historically had
     * occasional non-JSON warning lines and we don't want to crash on them.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function parseNdjson(StreamInterface $stream, int $chunkSize = 8192): \Generator
    {
        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read($chunkSize);
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        }

        $line = trim($buffer);
        if ($line !== '') {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                yield $decoded;
            }
        }
    }
}
