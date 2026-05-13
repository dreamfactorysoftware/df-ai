<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Models\AiPromptLog;
use DreamFactory\Core\AI\Models\AiUsageLog;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Pushes ECS-shaped audit events out to per-AI-Connection sinks:
 *   - HTTP webhook (configurable format: ECS / Splunk HEC / Datadog)
 *   - File sink (NDJSON, for Logstash file-input plugin)
 *
 * Called from the resource layer immediately after a usage row is
 * written. Failures are best-effort logged — a webhook timeout must
 * never break the actual AI response. The operating principle: SIEM
 * forwarding is supplementary; the source of truth is the ai_usage_log
 * + ai_prompt_log tables, which the audit-stream pull endpoint
 * exposes regardless of webhook health.
 *
 * For high-volume installs, plan to dispatch via Laravel queue jobs
 * rather than synchronously — that's a follow-up. For now, this is
 * synchronous with a tight 5-second timeout per webhook so a slow
 * SIEM doesn't pile up requests on PHP-FPM.
 */
class AuditDispatcher
{
    /** Per-AI-Connection config cache for the lifetime of the request. */
    private static array $configCache = [];

    /** Lazy Guzzle client for outbound webhooks. */
    private static ?Client $client = null;

    /**
     * Push audit events for a freshly-written ai_usage_log row to all
     * configured sinks for that AI Connection.
     *
     * @param int $serviceId AI Connection service id whose sinks should fire
     * @param string $requestId UUID identifying the row(s) to ship
     */
    public static function dispatch(int $serviceId, string $requestId): void
    {
        $config = self::loadConfig($serviceId);
        if (!$config) {
            return;
        }

        $hasWebhook = !empty($config->audit_webhook_url);
        $hasFileSink = (bool) ($config->audit_file_sink_enabled ?? false);
        if (!$hasWebhook && !$hasFileSink) {
            return; // No sinks configured; nothing to do.
        }

        // Pull the rows for the event. Best-effort — if either query
        // fails, we log and move on; the pull endpoint will still
        // expose the data later for the SIEM to scrape.
        try {
            $usage = AiUsageLog::query()->where('request_id', $requestId)->first();
            if (!$usage) {
                return;
            }
            $prompt = AiPromptLog::query()->where('request_id', $requestId)->first();
        } catch (\Throwable $e) {
            Log::warning('Audit dispatch: failed loading rows for ' . $requestId . ': ' . $e->getMessage());
            return;
        }

        $event = AuditStreamFormatter::fromUsage(
            $usage->toArray(),
            $prompt?->toArray(),
        );

        if ($hasWebhook) {
            self::sendWebhook(
                (string) $config->audit_webhook_url,
                (string) ($config->audit_webhook_format ?? 'ecs'),
                (string) ($config->audit_webhook_auth_header ?? ''),
                $event,
            );
        }

        if ($hasFileSink) {
            self::writeFileSink($event);
        }
    }

    /**
     * POST a single event to a webhook URL with a chosen format envelope.
     */
    public static function sendWebhook(string $url, string $format, string $authHeader, array $ecsEvent): void
    {
        $payload = match (strtolower($format)) {
            'splunk_hec', 'splunk' => AuditStreamFormatter::toSplunkHec($ecsEvent),
            'datadog'              => AuditStreamFormatter::toDatadog($ecsEvent),
            default                => $ecsEvent,
        };

        $headers = ['Content-Type' => 'application/json'];
        if ($authHeader !== '') {
            $headers['Authorization'] = $authHeader;
        }

        try {
            self::client()->request('POST', $url, [
                'json'    => $payload,
                'headers' => $headers,
                'timeout' => 5, // Tight timeout — SIEM forwarding must not pile up on slow downstream.
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit webhook to ' . $url . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Append a single ECS event as one NDJSON line to the configured
     * file sink. Path is config-driven (default per the global config
     * file). Default path follows the standard Linux logging layout
     * so Logstash file-input config is one line.
     */
    public static function writeFileSink(array $ecsEvent): void
    {
        $path = (string) config('df-ai.audit_file_sink.path', '/var/log/dreamfactory/ai-audit.log');
        $line = AuditStreamFormatter::toNdjsonLine($ecsEvent) . "\n";

        try {
            // Atomic append. PHP's file_put_contents with FILE_APPEND +
            // LOCK_EX is safe across PHP-FPM workers — Logstash's file
            // input handles partial-line cases via tail+inode tracking.
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            Log::warning('Audit file sink write failed: ' . $e->getMessage());
        }
    }

    /** Test/internal hook to drop the static cache. */
    public static function flushCache(): void
    {
        self::$configCache = [];
    }

    private static function loadConfig(int $serviceId): ?AiConnectionConfig
    {
        if (!array_key_exists($serviceId, self::$configCache)) {
            $cfg = AiConnectionConfig::whereServiceId($serviceId)->first();
            if ($cfg) {
                $cfg->protectedView = false; // unmask audit_webhook_auth_header
            }
            self::$configCache[$serviceId] = $cfg;
        }
        return self::$configCache[$serviceId];
    }

    private static function client(): Client
    {
        if (self::$client === null) {
            self::$client = new Client(['timeout' => 5]);
        }
        return self::$client;
    }
}
