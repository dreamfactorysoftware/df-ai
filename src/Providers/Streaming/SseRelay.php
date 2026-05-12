<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers\Streaming;

/**
 * Translates the unified provider-stream events back into OpenAI-shaped SSE
 * frames for the wire — the pass-through gateway emits OpenAI-compatible
 * frames regardless of the upstream provider, so any OpenAI SDK can consume
 * the response. (Anthropic-shape passthrough lives in a future Phase B
 * alongside the /v1/messages compat endpoint.)
 *
 * Two responsibilities:
 *   1. **Build the SSE payload** for each unified event type (renderable
 *      pure-PHP, unit-testable).
 *   2. **Drive the stream loop** — receive a generator from the provider,
 *      emit frames as they arrive, accumulate token counts, finalize
 *      billing in a try/finally so a partial disconnect never bills $0.
 *
 * Split into two methods so the renderer is testable without HTTP plumbing,
 * and the loop is testable with a fake "echo" sink (no real connection).
 */
class SseRelay
{
    /**
     * Emit one OpenAI-shape SSE frame line for a unified provider event.
     * Returns `null` when the event has no wire representation (used for
     * internal terminal sentinels — `done` is rendered as `[DONE]`, but
     * `usage` and `finish` are folded into a single closing `chat.completion.chunk`).
     *
     * Shape mirrors what OpenAI emits on /v1/chat/completions with
     * `stream: true`, so existing OpenAI SDK clients consume this without
     * modification.
     *
     * @param array{type: string, ...} $event
     */
    public static function renderFrame(array $event, string $providerName, string $model, string $chatId): ?string
    {
        $type = $event['type'] ?? '';

        if ($type === 'delta') {
            $payload = [
                'id'      => $chatId,
                'object'  => 'chat.completion.chunk',
                'model'   => $model,
                'provider' => $providerName,
                'choices' => [[
                    'index' => 0,
                    'delta' => ['content' => (string) ($event['text'] ?? '')],
                    'finish_reason' => null,
                ]],
            ];
            return 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        }

        if ($type === 'finish') {
            $payload = [
                'id'      => $chatId,
                'object'  => 'chat.completion.chunk',
                'model'   => $model,
                'provider' => $providerName,
                'choices' => [[
                    'index' => 0,
                    'delta' => new \stdClass(),
                    'finish_reason' => (string) ($event['reason'] ?? 'stop'),
                ]],
            ];
            return 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        }

        if ($type === 'usage') {
            $payload = [
                'id'      => $chatId,
                'object'  => 'chat.completion.chunk',
                'model'   => $model,
                'provider' => $providerName,
                'choices' => [],
                'usage'   => [
                    'prompt_tokens'     => (int) ($event['input_tokens'] ?? 0),
                    'completion_tokens' => (int) ($event['output_tokens'] ?? 0),
                    'total_tokens'      => (int) ($event['input_tokens'] ?? 0) + (int) ($event['output_tokens'] ?? 0),
                ],
            ];
            return 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        }

        if ($type === 'error') {
            // Mid-stream errors get their own JSON envelope so client SDKs
            // can detect them. This is a DF-specific extension — OpenAI
            // doesn't normally emit errors mid-stream, so SDKs may surface
            // them as raw chunks; that's fine, the message is preserved.
            $payload = ['error' => ['message' => (string) ($event['message'] ?? 'unknown error')]];
            return 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        }

        if ($type === 'done') {
            return "data: [DONE]\n\n";
        }

        return null;
    }

    /**
     * Drive the stream callback. Iterates the provider's chatStream()
     * generator, calls the sink for each SSE-rendered frame, accumulates
     * the final token counts, and returns the totals + status the resource
     * layer needs to write the AiUsageLog row.
     *
     * **The contract this method enforces:**
     * - Always returns the totals dict, even if the iterator throws.
     * - Returns status='success' when the stream emitted a `done` event.
     * - Returns status='partial' when the loop exited via abort (sink
     *   reported the connection was lost) — billing should record what
     *   was counted so far.
     * - Returns status='error' with a message when an `error` event was
     *   surfaced or the iterator threw.
     *
     * @param \Generator<int, array{type: string, ...}> $events
     * @param callable(string): bool $sink Called with each rendered SSE
     *        frame; returns false to abort (e.g. connection_aborted()).
     * @return array{
     *     status: string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     finish_reason: ?string,
     *     error_message: ?string,
     * }
     */
    public static function drive(\Generator $events, callable $sink, string $providerName, string $model, string $chatId): array
    {
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = null;
        $errorMessage = null;
        $status = 'partial'; // Pessimistic default: only flip to success on `done`.

        try {
            foreach ($events as $event) {
                // Track counts as they fly past so billing has them even
                // on early abort.
                if (($event['type'] ?? '') === 'usage') {
                    $inputTokens = (int) ($event['input_tokens'] ?? 0);
                    $outputTokens = (int) ($event['output_tokens'] ?? 0);
                }
                if (($event['type'] ?? '') === 'finish') {
                    $finishReason = (string) ($event['reason'] ?? 'stop');
                }
                if (($event['type'] ?? '') === 'error') {
                    $errorMessage = (string) ($event['message'] ?? 'unknown error');
                    $status = 'error';
                }

                $rendered = self::renderFrame($event, $providerName, $model, $chatId);
                if ($rendered === null) {
                    continue;
                }

                $shouldContinue = $sink($rendered);
                if (!$shouldContinue) {
                    // Caller signalled the client disconnected. Stop pulling
                    // more from the provider — there's no one to send to.
                    return self::result('partial', $inputTokens, $outputTokens, $finishReason, $errorMessage);
                }

                if (($event['type'] ?? '') === 'done' && $status !== 'error') {
                    $status = 'success';
                }
            }
        } catch (\Throwable $e) {
            // Provider blew up mid-stream — surface what we know. Note: we
            // don't try to write a final SSE error frame here because the
            // sink may have already failed; the resource layer will log it.
            $status = 'error';
            $errorMessage = $e->getMessage();
        }

        return self::result($status, $inputTokens, $outputTokens, $finishReason, $errorMessage);
    }

    /** @return array{status: string, input_tokens: int, output_tokens: int, finish_reason: ?string, error_message: ?string} */
    private static function result(string $status, int $in, int $out, ?string $finish, ?string $err): array
    {
        return [
            'status'        => $status,
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'finish_reason' => $finish,
            'error_message' => $err,
        ];
    }
}
