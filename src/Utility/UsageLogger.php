<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

use DreamFactory\Core\AI\Models\AiUsageLog;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Str;
use Log;

/**
 * Records AI usage to the ai_usage_log table after each provider call.
 */
class UsageLogger
{
    /**
     * Per-request UUID shared across every AiUsageLog row written during the
     * same HTTP request. Lets the Gateway view correlate AI calls with MCP
     * tool-call rows that fired in the same request.
     */
    private static ?string $requestId = null;

    /**
     * Log a successful AI request.
     *
     * @param array{provider?: string, model?: string, input_tokens?: int, output_tokens?: int, tool_call_count?: int} $result
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
     * Log a partially-delivered streaming request — the client disconnected
     * (or the upstream dropped) before the model produced its final token.
     *
     * Bills for the tokens that were actually counted, but flags the row
     * with status='partial' so dashboards can break it out from clean
     * `success` totals (a wave of partials usually points at a network or
     * timeout problem, not a model issue).
     *
     * @param array{provider?: string, model?: string, input_tokens?: int, output_tokens?: int, tool_call_count?: int} $result
     */
    public static function logPartial(
        int $serviceId,
        string $resource,
        array $result,
        int $latencyMs,
        ?string $reason = null,
    ): void {
        self::write($serviceId, $resource, $result, $latencyMs, 'partial', $reason);
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

    /**
     * Override the request id (e.g. from an inbound trace header). Resets
     * automatically at the end of the PHP request.
     */
    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = (string) Str::uuid();
        }
        return self::$requestId;
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
            $provider = $result['provider'] ?? 'unknown';
            $model = $result['model'] ?? 'unknown';
            $inputTokens = (int) ($result['input_tokens'] ?? 0);
            $outputTokens = (int) ($result['output_tokens'] ?? 0);

            // Partial deliveries (streaming, client disconnect) still bill
            // for the tokens we counted — only outright errors are zeroed.
            $costUsd = $status === 'error'
                ? 0.0
                : UsageRates::estimate($serviceId, $provider, $model, $inputTokens, $outputTokens);

            AiUsageLog::create([
                'service_id'      => $serviceId,
                'user_id'         => Session::getCurrentUserId(),
                'role_id'         => Session::getRoleId(),
                'app_id'          => Session::get('app.id'),
                'resource'        => $resource,
                'provider'        => $provider,
                'model'           => $model,
                'input_tokens'    => $inputTokens,
                'output_tokens'   => $outputTokens,
                'tool_call_count' => (int) ($result['tool_call_count'] ?? 0),
                'cost_usd'        => $costUsd,
                'latency_ms'      => $latencyMs,
                'status'          => $status,
                'error_message'   => $errorMessage,
                'request_id'      => self::requestId(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log AI usage: ' . $e->getMessage());
        }
    }
}
