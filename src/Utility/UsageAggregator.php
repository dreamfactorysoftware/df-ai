<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

use DreamFactory\Core\AI\Models\AiUsageLog;
use Illuminate\Support\Carbon;

/**
 * Aggregates rows from the ai_usage_log table for the org-wide
 * AI Usage Analytics dashboard.
 *
 * Pulled out of the ServiceProvider route closure so it can be
 * unit-tested without booting Laravel HTTP handling.
 */
class UsageAggregator
{
    /**
     * Parse a period string like "24h", "7d", "30d", "90d" into a Carbon
     * timestamp representing the inclusive lower bound. Defaults to 7d.
     */
    public static function parsePeriod(string $period, ?Carbon $now = null): Carbon
    {
        $now = $now ?? Carbon::now();
        if (preg_match('/^(\d+)d$/', $period, $m)) {
            return $now->copy()->subDays((int) $m[1]);
        }
        if (preg_match('/^(\d+)h$/', $period, $m)) {
            return $now->copy()->subHours((int) $m[1]);
        }
        return $now->copy()->subDays(7);
    }

    /**
     * Run the aggregation against the ai_usage_log table for rows since
     * the given timestamp. Returns the JSON-shape the dashboard consumes.
     *
     * @param Carbon $since lower-bound timestamp (inclusive)
     * @param string $driver DB driver name; controls SQL date-format syntax
     */
    public static function aggregate(Carbon $since, string $driver = 'mysql'): array
    {
        $base = AiUsageLog::query()->where('created_at', '>=', $since);

        $totalRequests = (clone $base)->count();
        $totalInput = (int) (clone $base)->sum('input_tokens');
        $totalOutput = (int) (clone $base)->sum('output_tokens');
        $errorCount = (clone $base)->where('status', 'error')->count();
        $avgLatency = (int) round((float) (clone $base)->avg('latency_ms'));

        $byService = (clone $base)
            ->selectRaw(
                'service_id, COUNT(*) as requests, '
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens, '
                . 'AVG(latency_ms) as avg_latency, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as errors',
                ['error']
            )
            ->groupBy('service_id')
            ->get()
            ->toArray();

        $byUser = (clone $base)
            ->selectRaw(
                'user_id, COUNT(*) as requests, '
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens'
            )
            ->groupBy('user_id')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byRole = (clone $base)
            ->selectRaw(
                'role_id, COUNT(*) as requests, '
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens'
            )
            ->groupBy('role_id')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byProvider = (clone $base)
            ->selectRaw(
                'provider, COUNT(*) as requests, '
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens'
            )
            ->groupBy('provider')
            ->get()
            ->toArray();

        $byModel = (clone $base)
            ->selectRaw(
                'model, provider, COUNT(*) as requests, '
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens'
            )
            ->groupBy('model', 'provider')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byResource = (clone $base)
            ->selectRaw('resource, COUNT(*) as requests')
            ->groupBy('resource')
            ->get()
            ->toArray();

        $dateExpr = self::dateExpression($driver);
        $series = (clone $base)
            ->selectRaw(
                "$dateExpr as date, COUNT(*) as requests, "
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as errors',
                ['error']
            )
            ->groupBy(\DB::raw($dateExpr))
            ->orderBy(\DB::raw($dateExpr))
            ->get()
            ->toArray();

        return [
            'since'               => $since->toIso8601String(),
            'total_requests'      => $totalRequests,
            'total_input_tokens'  => $totalInput,
            'total_output_tokens' => $totalOutput,
            'errors'              => $errorCount,
            'avg_latency_ms'      => $avgLatency,
            'by_service'          => $byService,
            'by_user'             => $byUser,
            'by_role'             => $byRole,
            'by_provider'         => $byProvider,
            'by_model'            => $byModel,
            'by_resource'         => $byResource,
            'series'              => $series,
        ];
    }

    /**
     * SQL fragment that truncates created_at to YYYY-MM-DD. Different
     * databases use different functions for this — keep them parallel.
     */
    public static function dateExpression(string $driver): string
    {
        if ($driver === 'sqlite') {
            return "strftime('%Y-%m-%d', created_at)";
        }
        if ($driver === 'pgsql') {
            return "to_char(created_at, 'YYYY-MM-DD')";
        }
        // MySQL / MariaDB default
        return "DATE_FORMAT(created_at, '%Y-%m-%d')";
    }
}
