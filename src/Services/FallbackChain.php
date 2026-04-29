<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Services;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Providers\AiProviderFactory;
use DreamFactory\Core\AI\Providers\AiProviderInterface;

/**
 * Walks a primary AI Connection and its configured fallback chain on
 * retryable provider errors. The first attempt that returns a result
 * wins; if all attempts fail, the last exception bubbles up.
 *
 * **What counts as retryable:**
 *   - 429 (rate limited)         — try the next provider, may have headroom
 *   - 5xx (server error)         — provider is having a moment
 *   - connection timeout         — likely network, not the request shape
 *
 * **What does NOT trigger fallback:**
 *   - 401 / 403                  — the key is bad, won't be better elsewhere
 *   - 400 / 422                  — the request shape is wrong, won't change
 *   - LogicException             — programmer error, fallback hides bugs
 *
 * This logic mirrors {@see AiProviderException::isRetryable()} so the
 * decision is consistent with the in-provider retry mechanism.
 *
 * Each attempt fires the caller's closure separately, which means the
 * caller is responsible for writing one ai_usage_log row per attempt.
 * That's intentional — billing transparency. A customer should see
 * "Anthropic 429 → OpenAI succeeded" as two rows, not one fudged row.
 *
 * @template T
 */
class FallbackChain
{
    /**
     * Run a closure against a primary service id and its fallbacks.
     *
     * @param int $primaryServiceId       Service id to try first
     * @param callable(AiProviderInterface, int, int): mixed $fn
     *        Closure that receives (provider, serviceId, attemptIndex).
     *        attemptIndex is 0 for primary, 1+ for fallbacks. Throws
     *        AiProviderException on failure; closure may also write
     *        usage logs / dispatch audit events per attempt.
     * @return mixed Whatever the closure returns on the first success
     * @throws AiProviderException If every attempt failed retryably,
     *         or any attempt failed non-retryably (no fallback)
     */
    public static function execute(int $primaryServiceId, callable $fn): mixed
    {
        $serviceIds = self::resolveChain($primaryServiceId);
        $lastException = null;

        foreach ($serviceIds as $index => $serviceId) {
            try {
                $provider = AiProviderFactory::fromServiceId($serviceId);
                return $fn($provider, $serviceId, $index);
            } catch (AiProviderException $e) {
                if (!$e->isRetryable()) {
                    // 4xx other than 429: re-shapes won't help. Throw
                    // immediately rather than wasting fallback budget
                    // on a request that can never succeed.
                    throw $e;
                }
                $lastException = $e;
                // Continue to next fallback.
            }
        }

        // Every attempt was retryable but failed. Surface the last error.
        throw $lastException ?? new AiProviderException(
            'All providers in the fallback chain failed.'
        );
    }

    /**
     * Resolve the ordered list of service ids to try. First entry is
     * always the primary; subsequent entries come from the primary's
     * configured fallback_service_ids. Pure / exposed for testing.
     *
     * @return array<int, int>
     */
    public static function resolveChain(int $primaryServiceId): array
    {
        $chain = [$primaryServiceId];

        $config = AiConnectionConfig::whereServiceId($primaryServiceId)->first();
        if (!$config) {
            return $chain;
        }

        $fallbacks = $config->fallback_service_ids;
        if (is_string($fallbacks)) {
            $fallbacks = json_decode($fallbacks, true);
        }
        if (!is_array($fallbacks)) {
            return $chain;
        }

        foreach ($fallbacks as $svcId) {
            $svcId = (int) $svcId;
            // Skip duplicates and self-references — the chain order matters
            // but a circular reference would cause an infinite retry loop.
            if ($svcId > 0 && !in_array($svcId, $chain, true)) {
                $chain[] = $svcId;
            }
        }

        return $chain;
    }
}
