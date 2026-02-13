<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

/**
 * OpenAI provider — thin wrapper that sets the correct provider name.
 *
 * The API is identical to OpenAICompatibleProvider (same spec).
 */
class OpenAIProvider extends OpenAICompatibleProvider
{
    public function getProviderName(): string
    {
        return 'openai';
    }
}
