<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Utility;

use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Models\AiUsageLog;
use Illuminate\Database\Eloquent\Builder;
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
    /** Filter keys accepted by aggregate(). Other keys are ignored. */
    public const FILTER_KEYS = [
        'provider',
        'service_id',
        'model',
        'user_id',
        'role_id',
        'app_id',
        'resource',
        'status',
    ];

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
     * @param array<string, mixed> $filters keys from FILTER_KEYS; values are
     *        scalar or array (treated as IN clause). Unknown keys ignored.
     */
    public static function aggregate(
        Carbon $since,
        string $driver = 'mysql',
        array $filters = [],
        ?Carbon $until = null,
    ): array {
        $base = AiUsageLog::query()->where('created_at', '>=', $since);
        if ($until) {
            $base->where('created_at', '<', $until);
        }
        self::applyFilters($base, $filters);

        $totalRequests = (clone $base)->count();
        $totalInput = (int) (clone $base)->sum('input_tokens');
        $totalOutput = (int) (clone $base)->sum('output_tokens');
        $totalCostUsd = (float) (clone $base)->sum('cost_usd');
        $errorCount = (clone $base)->where('status', 'error')->count();
        $avgLatency = (int) round((float) (clone $base)->avg('latency_ms'));

        // Cross-DB latency percentiles via offset/order. SQLite, MySQL, MSSQL,
        // and Postgres all handle this — we can't rely on PERCENTILE_CONT
        // because SQLite lacks it. Each call is O(rows × log rows); fine up to
        // ~1M rows on a healthy index.
        $latencyP50 = self::percentile(clone $base, 0.50, $totalRequests);
        $latencyP95 = self::percentile(clone $base, 0.95, $totalRequests);
        $latencyP99 = self::percentile(clone $base, 0.99, $totalRequests);

        // Error breakdown by class — pull error_message strings only for the
        // failed rows (cap at 1k to keep this cheap; classification of a
        // larger sample isn't useful), classify in PHP.
        $errorRows = $errorCount > 0
            ? (clone $base)
                ->where('status', 'error')
                ->limit(1000)
                ->get(['error_message'])
                ->toArray()
            : [];
        $byErrorClass = self::groupErrors($errorRows, $errorCount);

        $byService = (clone $base)
            ->selectRaw(
                'service_id, COUNT(*) as requests, '
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens, '
                . 'SUM(cost_usd) as cost_usd, '
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
                . 'SUM(output_tokens) as output_tokens, '
                . 'SUM(cost_usd) as cost_usd'
            )
            ->groupBy('user_id')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byRole = (clone $base)
            ->selectRaw(
                'role_id, COUNT(*) as requests, '
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens, '
                . 'SUM(cost_usd) as cost_usd'
            )
            ->groupBy('role_id')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byApp = (clone $base)
            ->selectRaw(
                'app_id, COUNT(*) as requests, '
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens, '
                . 'SUM(cost_usd) as cost_usd'
            )
            ->groupBy('app_id')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byProvider = (clone $base)
            ->selectRaw(
                'provider, COUNT(*) as requests, '
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens, '
                . 'SUM(cost_usd) as cost_usd'
            )
            ->groupBy('provider')
            ->get()
            ->toArray();

        $byModel = (clone $base)
            ->selectRaw(
                'model, provider, COUNT(*) as requests, '
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens, '
                . 'SUM(cost_usd) as cost_usd'
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
                . 'SUM(cost_usd) as cost_usd, '
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
            'total_cost_usd'      => $totalCostUsd,
            'errors'              => $errorCount,
            'avg_latency_ms'      => $avgLatency,
            'latency_p50_ms'      => $latencyP50,
            'latency_p95_ms'      => $latencyP95,
            'latency_p99_ms'      => $latencyP99,
            'by_service'          => $byService,
            'by_user'             => $byUser,
            'by_role'             => $byRole,
            'by_app'              => $byApp,
            'by_provider'         => $byProvider,
            'by_model'            => $byModel,
            'by_resource'         => $byResource,
            'by_error_class'      => $byErrorClass,
            'series'              => $series,
            'filters'             => self::normalizeFiltersForResponse($filters),
        ];
    }

    /**
     * Per-service budget status for the current calendar month. Returns one
     * row per AI Connection with a non-null monthly_budget_usd, including
     * spent-this-month and a linear projection of where spend will land at
     * month-end. Does NOT take filters — budgets are configured at the
     * service level and asking "what's the budget given a provider filter"
     * isn't meaningful.
     *
     * @return array<int, array{service_id: int, monthly_budget_usd: float, spent_month_usd: float, projected_month_end_usd: float, days_into_month: int, days_in_month: int, on_track: bool}>
     */
    public static function budgetStatus(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $daysIntoMonth = max(1, $now->day);
        $daysInMonth = $now->daysInMonth;

        $configs = AiConnectionConfig::query()
            ->whereNotNull('monthly_budget_usd')
            ->where('monthly_budget_usd', '>', 0)
            ->get(['service_id', 'monthly_budget_usd']);

        $rows = [];
        foreach ($configs as $cfg) {
            $spent = (float) AiUsageLog::query()
                ->where('service_id', $cfg->service_id)
                ->where('created_at', '>=', $startOfMonth)
                ->sum('cost_usd');
            $projected = $spent * ($daysInMonth / $daysIntoMonth);
            $budget = (float) $cfg->monthly_budget_usd;
            $rows[] = [
                'service_id'              => (int) $cfg->service_id,
                'monthly_budget_usd'      => $budget,
                'spent_month_usd'         => round($spent, 6),
                'projected_month_end_usd' => round($projected, 6),
                'days_into_month'         => $daysIntoMonth,
                'days_in_month'           => $daysInMonth,
                'on_track'                => $projected <= $budget,
            ];
        }
        // Show the most-at-risk services first.
        usort($rows, function ($a, $b) {
            $aRatio = $a['monthly_budget_usd'] > 0 ? $a['projected_month_end_usd'] / $a['monthly_budget_usd'] : 0;
            $bRatio = $b['monthly_budget_usd'] > 0 ? $b['projected_month_end_usd'] / $b['monthly_budget_usd'] : 0;
            return $bRatio <=> $aRatio;
        });
        return $rows;
    }

    /**
     * Summary-only aggregation for the comparison block — totals and
     * percentiles for the [since, until) window, with the same filter
     * contract. No breakdowns or series. Cheap enough to call alongside the
     * full aggregate() without doubling page load time.
     *
     * @param array<string, mixed> $filters
     * @return array<string, int|float>
     */
    public static function summarize(
        Carbon $since,
        array $filters = [],
        ?Carbon $until = null,
    ): array {
        $base = AiUsageLog::query()->where('created_at', '>=', $since);
        if ($until) {
            $base->where('created_at', '<', $until);
        }
        self::applyFilters($base, $filters);

        $totalRequests = (clone $base)->count();
        $totalInput = (int) (clone $base)->sum('input_tokens');
        $totalOutput = (int) (clone $base)->sum('output_tokens');
        $totalCostUsd = (float) (clone $base)->sum('cost_usd');
        $errorCount = (clone $base)->where('status', 'error')->count();
        $avgLatency = (int) round((float) (clone $base)->avg('latency_ms'));

        return [
            'since'               => $since->toIso8601String(),
            'until'               => $until?->toIso8601String(),
            'total_requests'      => $totalRequests,
            'total_input_tokens'  => $totalInput,
            'total_output_tokens' => $totalOutput,
            'total_cost_usd'      => $totalCostUsd,
            'errors'              => $errorCount,
            'avg_latency_ms'      => $avgLatency,
            'latency_p50_ms'      => self::percentile(clone $base, 0.50, $totalRequests),
            'latency_p95_ms'      => self::percentile(clone $base, 0.95, $totalRequests),
            'latency_p99_ms'      => self::percentile(clone $base, 0.99, $totalRequests),
        ];
    }

    /**
     * Compute a percentile of latency_ms via OFFSET on the sorted column.
     * Returns 0 when the result set is empty. Cross-DB safe.
     */
    private static function percentile(Builder $query, float $p, int $count): int
    {
        if ($count < 1) {
            return 0;
        }
        // For a 1-indexed nearest-rank percentile, the position in a sorted
        // array of length N at percentile p is ceil(p * N) (clamped to [1, N]).
        // OFFSET is 0-indexed, so subtract 1.
        $offset = (int) max(0, min($count - 1, ceil($p * $count) - 1));
        $row = $query
            ->orderBy('latency_ms')
            ->offset($offset)
            ->limit(1)
            ->value('latency_ms');
        return (int) ($row ?? 0);
    }

    /**
     * Bucket an array of error_message strings into common failure modes.
     * Rough heuristics over substrings are good enough — the goal is "is the
     * 200-error spike a rate-limit problem or a model outage?"
     *
     * If the sample we classified is smaller than the true error count, the
     * counts are scaled up proportionally so the totals add up.
     *
     * @param array<int, array{error_message: ?string}|object> $rows
     * @return array<int, array{class: string, count: int}>
     */
    public static function groupErrors(array $rows, int $totalErrors): array
    {
        if (empty($rows) || $totalErrors === 0) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $msg = is_array($row) ? ($row['error_message'] ?? '') : ($row->error_message ?? '');
            $class = self::classifyError((string) $msg);
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }

        // If we sampled (limit hit), scale the counts up so the top-line
        // matches errors. Mostly cosmetic — keeps the chart honest at a glance.
        $sampled = array_sum($counts);
        $scale = $sampled > 0 ? $totalErrors / $sampled : 1.0;

        $out = [];
        foreach ($counts as $class => $n) {
            $out[] = ['class' => $class, 'count' => (int) round($n * $scale)];
        }
        usort($out, fn($a, $b) => $b['count'] <=> $a['count']);
        return $out;
    }

    public static function classifyError(string $msg): string
    {
        $m = strtolower($msg);
        if ($m === '') {
            return 'unknown';
        }
        if (str_contains($m, 'timed out') || str_contains($m, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($m, '429') || str_contains($m, 'rate limit') || str_contains($m, 'rate-limit')) {
            return 'rate_limit';
        }
        if (
            str_contains($m, '401') || str_contains($m, '403')
            || str_contains($m, 'unauthorized') || str_contains($m, 'invalid api key')
            || str_contains($m, 'authentication')
        ) {
            return 'auth';
        }
        if (str_contains($m, 'model') && (str_contains($m, '404') || str_contains($m, 'not found'))) {
            return 'model_not_found';
        }
        if (str_contains($m, 'context length') || str_contains($m, 'maximum context') || str_contains($m, 'too many tokens')) {
            return 'context_overflow';
        }
        if (str_contains($m, 'could not resolve') || str_contains($m, 'connection refused') || str_contains($m, 'cannot connect')) {
            return 'connectivity';
        }
        if (preg_match('/\b5\d\d\b/', $m)) {
            return 'provider_5xx';
        }
        if (preg_match('/\b4\d\d\b/', $m)) {
            return 'provider_4xx';
        }
        return 'other';
    }

    /**
     * Apply each known filter as an IN clause. Empty arrays / nulls /
     * unknown keys are skipped. Mutates the query in place.
     *
     * @param array<string, mixed> $filters
     */
    private static function applyFilters(Builder $query, array $filters): void
    {
        foreach (self::FILTER_KEYS as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }
            $values = $filters[$key];
            if ($values === null || $values === '' || $values === []) {
                continue;
            }
            $query->whereIn($key, is_array($values) ? array_values($values) : [$values]);
        }
    }

    /**
     * Filter the response so it only echoes back keys we honored. Helps
     * the UI sync chip state when "Other" / unknown params get dropped.
     *
     * @param array<string, mixed> $filters
     * @return array<string, array<int, mixed>>
     */
    private static function normalizeFiltersForResponse(array $filters): array
    {
        $out = [];
        foreach (self::FILTER_KEYS as $key) {
            if (empty($filters[$key])) {
                continue;
            }
            $out[$key] = is_array($filters[$key]) ? array_values($filters[$key]) : [$filters[$key]];
        }
        return $out;
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
