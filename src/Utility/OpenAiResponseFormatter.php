<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

use Illuminate\Support\Str;

/**
 * Translates DreamFactory's normalized provider response shape into
 * the OpenAI /v1/chat/completions response shape.
 *
 * **Why this exists:** the OpenAI compat endpoint accepts inbound
 * requests in OpenAI shape and routes them to whatever provider an
 * alias points at (Anthropic, Ollama, etc.). The provider returns a
 * normalized DF shape. To make the OpenAI SDK happy on the way back,
 * we wrap the DF shape in OpenAI's `{id, object, created, model,
 * choices: [...], usage: {...}}` envelope here.
 *
 * Streaming SSE is already OpenAI-shape (SseRelay::renderFrame emits
 * `chat.completion.chunk` frames), so the streaming compat path
 * doesn't need any translation — it just routes through the existing
 * stream relay.
 */
class OpenAiResponseFormatter
{
    /**
     * Wrap a DF normalized chat result in the OpenAI completion shape.
     *
     * @param array{
     *     content: string,
     *     provider?: string,
     *     model?: string,
     *     input_tokens?: int,
     *     output_tokens?: int,
     *     finish_reason?: string,
     * } $result
     * @param string $aliasName The logical alias the client asked for —
     *                          NOT the physical_model (clients want to
     *                          see the name they sent echoed back).
     * @return array<string, mixed> OpenAI-shape /v1/chat/completions response
     */
    public static function fromChatResult(array $result, string $aliasName): array
    {
        $inputTokens  = (int) ($result['input_tokens'] ?? 0);
        $outputTokens = (int) ($result['output_tokens'] ?? 0);

        return [
            'id'      => 'chatcmpl-' . Str::random(24),
            'object'  => 'chat.completion',
            'created' => time(),
            // Echo the alias the client sent, not the physical model.
            // OpenAI clients sometimes assert on this round-tripping.
            'model'   => $aliasName,
            'choices' => [[
                'index'   => 0,
                'message' => [
                    'role'    => 'assistant',
                    'content' => (string) ($result['content'] ?? ''),
                ],
                'finish_reason' => self::normalizeFinishReason(
                    (string) ($result['finish_reason'] ?? 'stop')
                ),
            ]],
            'usage' => [
                'prompt_tokens'     => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens'      => $inputTokens + $outputTokens,
            ],
            // DreamFactory-specific extensions — OpenAI clients ignore
            // unknown keys, so we add provider attribution and the
            // request id correlation handle without breaking compat.
            'df' => [
                'provider'   => (string) ($result['provider'] ?? ''),
                'request_id' => UsageLogger::requestId(),
            ],
        ];
    }

    /**
     * Map provider-specific finish reasons onto OpenAI's enum:
     *   stop           — model emitted a stop sequence / natural end
     *   length         — hit max_tokens
     *   tool_calls     — produced tool calls (rare in compat path; we
     *                    don't relay tool calls through the compat
     *                    endpoint yet, but pin the mapping anyway)
     *   content_filter — provider's safety system blocked output
     *
     * Unknown reasons map to "stop" (the safest default — clients
     * won't retry on stop).
     */
    public static function normalizeFinishReason(string $reason): string
    {
        // Anthropic uses "end_turn" / "max_tokens" / "stop_sequence" /
        // "tool_use". OpenAI uses "stop" / "length" / "tool_calls" /
        // "content_filter". Map in both directions.
        return match (strtolower($reason)) {
            'stop', 'end_turn', 'stop_sequence' => 'stop',
            'length', 'max_tokens'              => 'length',
            'tool_calls', 'tool_use'            => 'tool_calls',
            'content_filter', 'safety'          => 'content_filter',
            default                              => 'stop',
        };
    }

    /**
     * Build an OpenAI-shape error envelope for the gateway endpoint.
     * OpenAI SDK clients expect `{ error: { message, type, code } }`
     * with specific HTTP status codes — return that shape so existing
     * try/except blocks in customer code work unchanged.
     *
     * @return array<string, mixed>
     */
    public static function errorEnvelope(string $message, string $type = 'invalid_request_error', ?string $code = null): array
    {
        $err = [
            'message' => $message,
            'type'    => $type,
            'param'   => null,
        ];
        if ($code !== null) {
            $err['code'] = $code;
        }
        return ['error' => $err];
    }
}
