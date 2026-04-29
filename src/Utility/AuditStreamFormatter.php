<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

use DreamFactory\Core\AI\Models\AiPromptLog;
use DreamFactory\Core\AI\Models\AiUsageLog;

/**
 * Shapes ai_usage_log + ai_prompt_log rows into Elastic Common Schema
 * (ECS) events for SIEM consumption.
 *
 * **Why ECS specifically:** It's the most widely consumed structured
 * event format across Logstash, Elastic, Splunk pipelines, Datadog
 * log processors, Sumo Logic, and most modern SIEMs. By emitting ECS
 * once, every supported SIEM works without per-vendor transformations.
 *
 * The shape we emit is one JSON object per event, suitable for:
 *   - NDJSON output (newline-delimited): the audit-stream endpoint
 *   - File sink: writes one line per event to a tail-able file
 *   - Webhook push: each POST body is a single event JSON object
 *
 * ECS field reference: https://www.elastic.co/guide/en/ecs/current/
 *
 * Custom (non-ECS) fields land under the `df.*` namespace per ECS's
 * "Custom Fields" guidance (private namespace prefix).
 */
class AuditStreamFormatter
{
    /**
     * Convert an AiUsageLog row (optionally joined with its prompt log
     * row) to the ECS shape.
     *
     * @param array<string, mixed> $usage    Single ai_usage_log row (associative)
     * @param array<string, mixed>|null $prompt Optional joined ai_prompt_log row
     * @return array<string, mixed>           ECS-shaped event
     */
    public static function fromUsage(array $usage, ?array $prompt = null): array
    {
        $isError = ($usage['status'] ?? '') === 'error';
        $costUsd = (float) ($usage['cost_usd'] ?? 0);

        $event = [
            '@timestamp' => self::isoTime($usage['created_at'] ?? null),

            'event' => [
                'kind'     => 'event',
                'category' => ['process', 'web'],
                'type'     => $isError ? ['denied'] : ['info'],
                'action'   => 'ai_request',
                'outcome'  => self::outcomeFromStatus((string) ($usage['status'] ?? 'success')),
                'id'       => (string) ($usage['request_id'] ?? ''),
                'dataset'  => 'dreamfactory.ai_gateway',
                'duration' => self::nanosFromMs((int) ($usage['latency_ms'] ?? 0)),
            ],

            'service' => [
                'name' => 'dreamfactory',
                'type' => 'ai_gateway',
                'id'   => (int) ($usage['service_id'] ?? 0),
            ],

            'user' => self::userBlock($usage),

            // ECS doesn't standardize AI metrics yet — under df.ai.* per
            // the ECS custom-fields recommendation. Most SIEMs index
            // unknown fields automatically so dashboards work without
            // schema work.
            'df' => [
                'request_id' => (string) ($usage['request_id'] ?? ''),
                'role_id'    => self::nullableInt($usage['role_id'] ?? null),
                'app_id'     => self::nullableInt($usage['app_id'] ?? null),
                'service_id' => (int) ($usage['service_id'] ?? 0),
                'resource'   => (string) ($usage['resource'] ?? ''),
                'ai' => [
                    'provider'        => (string) ($usage['provider'] ?? ''),
                    'model'           => (string) ($usage['model'] ?? ''),
                    'input_tokens'    => (int) ($usage['input_tokens'] ?? 0),
                    'output_tokens'   => (int) ($usage['output_tokens'] ?? 0),
                    'tool_call_count' => (int) ($usage['tool_call_count'] ?? 0),
                    'cost_usd'        => $costUsd,
                    'latency_ms'      => (int) ($usage['latency_ms'] ?? 0),
                    'status'          => (string) ($usage['status'] ?? 'success'),
                ],
            ],
        ];

        // Top-level message field is what most SIEMs show by default in
        // the event list view. Make it scan-friendly.
        $event['message'] = self::summaryLine($usage);

        if ($isError && !empty($usage['error_message'])) {
            $event['error'] = [
                'message' => (string) $usage['error_message'],
            ];
        }

        // Attach prompt audit when provided. Renamed to ECS-friendly
        // shape — `prompt`/`response` are df.* extensions (no ECS
        // standard), but include `original_size_bytes` so analysts can
        // see at a glance how aggressive redaction was.
        if ($prompt !== null) {
            $event['df']['prompt'] = [
                'redacted_text'    => (string) ($prompt['request_payload'] ?? ''),
                'redaction_count'  => (int) ($prompt['redaction_count'] ?? 0),
                'original_size'    => (int) ($prompt['original_size_bytes'] ?? 0),
            ];
            $event['df']['response'] = [
                'redacted_text' => (string) ($prompt['response_payload'] ?? ''),
            ];
        }

        return $event;
    }

    /**
     * Wrap an ECS event in a Splunk HEC envelope. Splunk's HTTP Event
     * Collector expects:
     *   { "time": <epoch>, "host": "...", "source": "...",
     *     "sourcetype": "...", "event": { ...payload... } }
     *
     * @param array<string, mixed> $ecsEvent
     * @return array<string, mixed>
     */
    public static function toSplunkHec(array $ecsEvent, string $sourcetype = '_json'): array
    {
        $ts = $ecsEvent['@timestamp'] ?? null;
        $epoch = is_string($ts) ? strtotime($ts) : time();
        return [
            'time'       => $epoch ?: time(),
            'sourcetype' => $sourcetype,
            'source'     => 'dreamfactory:ai_gateway',
            'event'      => $ecsEvent,
        ];
    }

    /**
     * Wrap an ECS event in a Datadog logs envelope. Datadog accepts ECS
     * natively but expects `service` + `ddsource` + `ddtags` at the top
     * level for routing/filtering inside Datadog's UI.
     *
     * @param array<string, mixed> $ecsEvent
     * @return array<string, mixed>
     */
    public static function toDatadog(array $ecsEvent): array
    {
        $ecsEvent['ddsource'] = 'dreamfactory';
        $ecsEvent['service']  = ['name' => 'dreamfactory_ai_gateway'];
        // env: read from APP_ENV directly (not via config()) so this stays
        // pure / unit-testable without booting Laravel. Falls back to
        // 'production' which is the safest default for unknown deployments.
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        $ecsEvent['ddtags']   = sprintf(
            'env:%s,provider:%s,model:%s',
            (string) $env,
            (string) ($ecsEvent['df']['ai']['provider'] ?? 'unknown'),
            (string) ($ecsEvent['df']['ai']['model'] ?? 'unknown'),
        );
        return $ecsEvent;
    }

    /**
     * Encode an ECS event as a single NDJSON line (no trailing newline —
     * caller appends \n for batch writes).
     *
     * @param array<string, mixed> $ecsEvent
     */
    public static function toNdjsonLine(array $ecsEvent): string
    {
        return json_encode($ecsEvent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Build a stream of ECS events for a window of ai_usage_log rows.
     * Joins the prompt log on request_id when prompts were stored.
     * Used by the audit-stream endpoint.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function streamForWindow(\DateTimeInterface $since, ?\DateTimeInterface $until = null, int $limit = 1000): \Generator
    {
        $query = AiUsageLog::query()
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit);
        if ($until) {
            $query->where('created_at', '<', $until);
        }

        $usageRows = $query->get()->toArray();
        if (empty($usageRows)) {
            return;
        }

        $requestIds = array_filter(array_column($usageRows, 'request_id'));
        $prompts = empty($requestIds)
            ? []
            : AiPromptLog::query()
                ->whereIn('request_id', $requestIds)
                ->get()
                ->keyBy('request_id')
                ->toArray();

        foreach ($usageRows as $usage) {
            $prompt = $prompts[$usage['request_id'] ?? ''] ?? null;
            yield self::fromUsage($usage, $prompt);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────

    private static function isoTime(mixed $createdAt): string
    {
        if ($createdAt instanceof \DateTimeInterface) {
            return $createdAt->format('Y-m-d\TH:i:s.v\Z');
        }
        if (is_string($createdAt) && $createdAt !== '') {
            $ts = strtotime($createdAt);
            if ($ts !== false) {
                return gmdate('Y-m-d\TH:i:s.000\Z', $ts);
            }
        }
        return gmdate('Y-m-d\TH:i:s.000\Z');
    }

    private static function outcomeFromStatus(string $status): string
    {
        return match ($status) {
            'success' => 'success',
            'partial' => 'unknown', // ECS doesn't have "partial" — closest is unknown
            'error'   => 'failure',
            default   => 'unknown',
        };
    }

    /**
     * @param array<string, mixed> $usage
     * @return array<string, mixed>
     */
    private static function userBlock(array $usage): array
    {
        $userId = $usage['user_id'] ?? null;
        if ($userId === null) {
            return [];
        }
        return [
            'id' => (string) (int) $userId,
        ];
    }

    private static function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (int) $v;
    }

    private static function nanosFromMs(int $ms): int
    {
        return $ms * 1_000_000;
    }

    /**
     * @param array<string, mixed> $usage
     */
    private static function summaryLine(array $usage): string
    {
        return sprintf(
            'AI %s %s/%s in=%d out=%d cost=%.6f lat=%dms',
            $usage['resource'] ?? '?',
            $usage['provider'] ?? '?',
            $usage['model'] ?? '?',
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (float) ($usage['cost_usd'] ?? 0),
            (int) ($usage['latency_ms'] ?? 0),
        );
    }
}
