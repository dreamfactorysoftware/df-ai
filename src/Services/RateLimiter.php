<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Services;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Cache;

/**
 * Per-service rate limiting for AI requests using Laravel's Cache.
 *
 * Configured via the service's `rate_limit_per_minute` config value.
 * A value of 0 (default) means unlimited.
 */
class RateLimiter
{
    /**
     * Check the rate limit for a service and throw if exceeded.
     *
     * @throws AiProviderException with HTTP 429 when the limit is exceeded
     */
    public static function check(int $serviceId, string $providerName): void
    {
        $limit = (int) self::getServiceLimit($serviceId);

        if ($limit <= 0) {
            return; // Unlimited
        }

        $key = "ai_rate_limit:{$serviceId}";
        $current = (int) Cache::get($key, 0);

        if ($current >= $limit) {
            throw AiProviderException::rateLimited($providerName, 60);
        }

        // Increment the counter; set expiry to 60 seconds on first hit
        if ($current === 0) {
            Cache::put($key, 1, 60);
        } else {
            Cache::increment($key);
        }
    }

    /**
     * Get the rate limit for a service from its config.
     */
    private static function getServiceLimit(int $serviceId): int
    {
        $service = \ServiceManager::getServiceById($serviceId);

        if (!$service) {
            return 0;
        }

        $config = $service->getConfig();

        return (int) ($config['rate_limit_per_minute'] ?? 0);
    }
}
