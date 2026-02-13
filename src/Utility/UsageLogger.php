<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

use DreamFactory\Core\AI\Models\AiUsageLog;
use DreamFactory\Core\Utility\Session;
use Log;

/**
 * Records AI usage to the ai_usage_log table after each provider call.
 */
class UsageLogger
{
    /**
     * Log a successful AI request.
     */
    public static function logSuccess(
        int $serviceId,
        string $resource,
        array $result,
        int $latencyMs,
    ): void {
        self::write($serviceId, $resource, $result, $latencyMs, 'success');
    }

    /**
     * Log a failed AI request.
     */
    public static function logError(
        int $serviceId,
        string $resource,
        string $provider,
        string $model,
        int $latencyMs,
        string $errorMessage,
    ): void {
        self::write($serviceId, $resource, [
            'provider'      => $provider,
            'model'         => $model,
            'input_tokens'  => 0,
            'output_tokens' => 0,
        ], $latencyMs, 'error', $errorMessage);
    }

    private static function write(
        int $serviceId,
        string $resource,
        array $result,
        int $latencyMs,
        string $status,
        ?string $errorMessage = null,
    ): void {
        if (!config('df-ai.usage_logging.enabled', true)) {
            return;
        }

        try {
            AiUsageLog::create([
                'service_id'    => $serviceId,
                'user_id'       => Session::getCurrentUserId(),
                'role_id'       => Session::getRoleId(),
                'resource'      => $resource,
                'provider'      => $result['provider'] ?? 'unknown',
                'model'         => $result['model'] ?? 'unknown',
                'input_tokens'  => $result['input_tokens'] ?? 0,
                'output_tokens' => $result['output_tokens'] ?? 0,
                'latency_ms'    => $latencyMs,
                'status'        => $status,
                'error_message' => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log AI usage: ' . $e->getMessage());
        }
    }
}
