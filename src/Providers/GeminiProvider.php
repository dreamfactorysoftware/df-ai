<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

/**
 * Google Gemini provider.
 *
 * Gemini exposes an OpenAI-compatible surface, so we reuse the whole
 * OpenAI-compatible request/response/tool/stream machinery and only redirect
 * the routes. Two differences from a stock OpenAI server:
 *
 *   - The compat routes live under `/v1beta/openai`, not host-root `/v1`.
 *   - The host is fixed (generativelanguage.googleapis.com); base_url only
 *     needs the scheme+host, which the factory default supplies.
 *
 * Auth is a plain `Authorization: Bearer <key>`, identical to OpenAI, so the
 * inherited buildAuthHeaders() already does the right thing.
 */
class GeminiProvider extends OpenAICompatibleProvider
{
    protected string $pathPrefix = '/v1beta/openai';

    public function getProviderName(): string
    {
        return 'gemini';
    }
}
