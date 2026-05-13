<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

use Illuminate\Http\Request;

/**
 * Normalizes filter query params for the Gateway dashboard endpoints.
 *
 * Each filter key may arrive as repeated query params
 * (`?service_id=1&service_id=2`) or as a CSV value
 * (`?provider=anthropic,openai`). The aggregator only wants arrays, so this
 * collapses both shapes into a uniform shape and silently drops empties.
 *
 * Shared by /_internal/ai/usage (df-ai) and /_internal/ai/mcp-usage
 * (df-mcp-server) so the wire contract stays identical between them.
 */
class FilterRequestParser
{
    /**
     * @param Request $request
     * @param array<int, string> $allowedKeys keys to consider; unknown keys ignored
     * @return array<string, array<int, string>>
     */
    public static function parse(Request $request, array $allowedKeys): array
    {
        $filters = [];
        foreach ($allowedKeys as $key) {
            if (!$request->has($key)) {
                continue;
            }
            $raw = $request->get($key);
            $values = is_array($raw)
                ? $raw
                : array_filter(
                    array_map('trim', explode(',', (string) $raw)),
                    fn($v) => $v !== ''
                );
            if (!empty($values)) {
                $filters[$key] = array_values($values);
            }
        }
        return $filters;
    }
}
