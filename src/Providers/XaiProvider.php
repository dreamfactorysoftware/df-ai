<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

/**
 * xAI (Grok) provider.
 *
 * xAI's API is fully OpenAI-compatible, so we just override the provider name.
 */
class XaiProvider extends OpenAICompatibleProvider
{
    public function getProviderName(): string
    {
        return 'xai';
    }
}
