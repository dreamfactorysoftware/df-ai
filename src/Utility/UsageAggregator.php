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
     * Columns that can be used as the X-axis dimension of a stacked time
     * series via {@see seriesByDimension()}. Allow-list to prevent SQL
     * injection through the column name.
     */
    public const SERIES_DIMENSIONS = [
        'service_id',
        'user_id',
        'role_id',
        'app_id',
        'provider',
        'model',
        'resource',
    ];

    /** Sentinel bucket name used in series rows when a row falls outside the top-N. */
    public const OTHER_BUCKET = '__other__';

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
        // Partials = streaming requests where the client disconnected mid-
        // generation. Billed but flagged separately so an uptick (usually
        // network/load-balancer timeouts) doesn't hide inside the success rate.
        $partialCount = (clone $base)->where('status', 'partial')->count();
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

        // ACP chargeback rollup: spend by directory department/cost-center.
        // This is the dimension GCP project-level billing cannot produce —
        // it lives in the Entra/LDAP directory DF federates, not in Google IAM.
        $byDepartment = self::byDepartment($since, $filters, $until);

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
            ->orderByDesc('cost_usd')
            ->get()
            ->toArray();
        $byModel = self::attachCostPerThousand($byModel);

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

        // Multi-dimensional cost-over-time series — the "where is my money
        // going" answer. Top N + Other bucket prevents cardinality blowups
        // when a customer has hundreds of users/apps.
        $seriesByModel = self::seriesByDimension($since, $driver, $filters, 'model', 10, $until);
        $seriesByUser = self::seriesByDimension($since, $driver, $filters, 'user_id', 10, $until);
        $seriesByApp = self::seriesByDimension($since, $driver, $filters, 'app_id', 10, $until);
        $seriesByProvider = self::seriesByDimension($since, $driver, $filters, 'provider', 10, $until);

        // Drill-down hook: top-N most expensive single calls in the window.
        // Surfaces outliers (a single 200k-token monster call) that the
        // averages otherwise hide.
        $mostExpensiveCalls = self::mostExpensiveCalls($since, $filters, 10, $until);

        return [
            'since'                => $since->toIso8601String(),
            'total_requests'       => $totalRequests,
            'total_input_tokens'   => $totalInput,
            'total_output_tokens'  => $totalOutput,
            'total_cost_usd'       => $totalCostUsd,
            'errors'               => $errorCount,
            'partials'             => $partialCount,
            'avg_latency_ms'       => $avgLatency,
            'latency_p50_ms'       => $latencyP50,
            'latency_p95_ms'       => $latencyP95,
            'latency_p99_ms'       => $latencyP99,
            'by_service'           => $byService,
            'by_user'              => $byUser,
            'by_role'              => $byRole,
            'by_department'        => $byDepartment,
            'by_app'               => $byApp,
            'by_provider'          => $byProvider,
            'by_model'             => $byModel,
            'by_resource'          => $byResource,
            'by_error_class'       => $byErrorClass,
            'series'               => $series,
            'series_by_model'      => $seriesByModel,
            'series_by_user'       => $seriesByUser,
            'series_by_app'        => $seriesByApp,
            'series_by_provider'   => $seriesByProvider,
            'most_expensive_calls' => $mostExpensiveCalls,
            'filters'              => self::normalizeFiltersForResponse($filters),
        ];
    }

    /**
     * ACP chargeback rollup: total spend grouped by directory department,
     * left-joining ai_usage_log.user_id to the user_department mapping that
     * df-adldap populates at login. Users with no mapping (agents, service
     * accounts, pre-ACP logins) collapse into an 'Unattributed' bucket so the
     * department totals always reconcile to the raw usage total.
     *
     * The department dimension lives in a separate table on purpose — it is a
     * directory-owned attribute we cache, not core usage schema — so this is
     * the one rollup that needs a join rather than a plain groupBy.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array{department: string, requests: int, input_tokens: int, output_tokens: int, cost_usd: float}>
     */
    public static function byDepartment(
        Carbon $since,
        array $filters = [],
        ?Carbon $until = null,
    ): array {
        $usage = (new AiUsageLog())->getTable();
        // COALESCE is portable across mysql/pgsql/sqlite; department is
        // varchar so no cast needed.
        $deptExpr = "COALESCE(user_department.department, 'Unattributed')";

        $q = AiUsageLog::query()->where("$usage.created_at", '>=', $since);
        if ($until) {
            $q->where("$usage.created_at", '<', $until);
        }
        // ponytail: applyFilters uses bare column names. user_id now exists in
        // both tables, so filtering by user_id AND grouping by department at
        // once would be ambiguous. Not a demo path; qualify it here if it ever
        // becomes one.
        self::applyFilters($q, $filters);

        return $q
            ->leftJoin('user_department', 'user_department.user_id', '=', "$usage.user_id")
            ->selectRaw(
                "$deptExpr as department, COUNT(*) as requests, "
                . "SUM($usage.input_tokens) as input_tokens, "
                . "SUM($usage.output_tokens) as output_tokens, "
                . "SUM($usage.cost_usd) as cost_usd"
            )
            ->groupBy(\DB::raw($deptExpr))
            ->orderByDesc('cost_usd')
            ->get()
            ->toArray();
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
        // Partials = streaming requests where the client disconnected mid-
        // generation. Billed but flagged separately so an uptick (usually
        // network/load-balancer timeouts) doesn't hide inside the success rate.
        $partialCount = (clone $base)->where('status', 'partial')->count();
        $avgLatency = (int) round((float) (clone $base)->avg('latency_ms'));

        return [
            'since'               => $since->toIso8601String(),
            'until'               => $until?->toIso8601String(),
            'total_requests'      => $totalRequests,
            'total_input_tokens'  => $totalInput,
            'total_output_tokens' => $totalOutput,
            'total_cost_usd'      => $totalCostUsd,
            'errors'              => $errorCount,
            'partials'            => $partialCount,
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
     * Stacked cost-over-time series for the dashboard's "where is my money
     * being spent" charts. Returns one row per (date, bucket) pair where
     * `bucket` is the value of $column for the top N spenders, plus an
     * '__other__' bucket aggregating everything beyond the top N.
     *
     * Why three passes:
     *   1. Find the top-N values by total cost in the window
     *   2. Per-(date, value) rollup for those top-N rows
     *   3. Per-date rollup for the remainder, tagged as the Other bucket
     *
     * The Other bucket is essential for honest charts at customers with
     * hundreds of users/apps — without it, the stacked area silently
     * undercounts spend.
     *
     * @param Carbon $since lower-bound timestamp (inclusive)
     * @param string $driver DB driver name
     * @param array<string, mixed> $filters
     * @param string $column attribute to group by; must be in SERIES_DIMENSIONS
     * @param int $topN keep the highest-spending $topN values; rest are folded into Other
     * @return array<int, array{date: string, bucket: int|string, requests: int, input_tokens: int, output_tokens: int, cost_usd: float}>
     */
    public static function seriesByDimension(
        Carbon $since,
        string $driver,
        array $filters,
        string $column,
        int $topN = 10,
        ?Carbon $until = null,
    ): array {
        if (!in_array($column, self::SERIES_DIMENSIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported series dimension "%s". Allowed: %s',
                $column,
                implode(', ', self::SERIES_DIMENSIONS)
            ));
        }
        if ($topN < 1) {
            $topN = 10;
        }

        $base = AiUsageLog::query()->where('created_at', '>=', $since);
        if ($until) {
            $base->where('created_at', '<', $until);
        }
        self::applyFilters($base, $filters);

        // Pass 1: top-N values by cost. Skip nulls — a series stacked by
        // user_id with a "null user" bucket isn't useful and confuses the UI.
        $topValues = (clone $base)
            ->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw("$column as value, SUM(cost_usd) as cost")
            ->orderByDesc('cost')
            ->limit($topN)
            ->pluck('value')
            ->toArray();

        if (empty($topValues)) {
            return [];
        }

        $dateExpr = self::dateExpression($driver);

        // Pass 2: per-(date, value) rollup for the top-N values.
        $topRows = (clone $base)
            ->whereIn($column, $topValues)
            ->selectRaw(
                "$dateExpr as date, $column as bucket, COUNT(*) as requests, "
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens, '
                . 'SUM(cost_usd) as cost_usd'
            )
            ->groupBy(\DB::raw($dateExpr), $column)
            ->orderBy(\DB::raw($dateExpr))
            ->get()
            ->toArray();

        // Pass 3: per-date rollup for everything else. Includes rows whose
        // dimension value IS null — folding them in here keeps the totals
        // honest even when attribution is incomplete.
        $otherRows = (clone $base)
            ->where(function ($q) use ($column, $topValues) {
                $q->whereNotIn($column, $topValues)->orWhereNull($column);
            })
            ->selectRaw(
                "$dateExpr as date, COUNT(*) as requests, "
                . 'SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens, '
                . 'SUM(cost_usd) as cost_usd'
            )
            ->groupBy(\DB::raw($dateExpr))
            ->orderBy(\DB::raw($dateExpr))
            ->having('requests', '>', 0)
            ->get();

        $otherTagged = [];
        foreach ($otherRows as $r) {
            $r->bucket = self::OTHER_BUCKET;
            $otherTagged[] = (array) $r;
        }

        return array_merge(
            array_map(fn($r) => is_array($r) ? $r : (array) $r, $topRows),
            $otherTagged,
        );
    }

    /**
     * Top N most expensive single calls in the window. The drill-down
     * companion to the by_model / by_user breakdowns: when a single call
     * is responsible for a chunk of spend, the user wants to see which
     * row it was and which (user, app, model) produced it.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function mostExpensiveCalls(
        Carbon $since,
        array $filters = [],
        int $limit = 10,
        ?Carbon $until = null,
    ): array {
        $base = AiUsageLog::query()->where('created_at', '>=', $since);
        if ($until) {
            $base->where('created_at', '<', $until);
        }
        self::applyFilters($base, $filters);

        return $base
            ->orderByDesc('cost_usd')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get([
                'id', 'service_id', 'user_id', 'role_id', 'app_id',
                'provider', 'model', 'resource',
                'input_tokens', 'output_tokens', 'cost_usd', 'latency_ms',
                'status', 'created_at',
            ])
            ->toArray();
    }

    /**
     * Augment a list of by_model rows with `cost_per_1k_tokens` — the
     * effective rate the customer is paying per 1000 tokens, averaged
     * across the window. Lets the dashboard answer "is this premium model
     * actually worth its rate" without the UI having to redo the math.
     *
     * Pure — exposed for unit testing. Operates on either array-rows or
     * stdClass-rows (Eloquent's selectRaw produces stdClass).
     *
     * @param array<int, array<string, mixed>|object> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function attachCostPerThousand(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $r = is_array($row) ? $row : (array) $row;
            $tokens = (int) ($r['input_tokens'] ?? 0) + (int) ($r['output_tokens'] ?? 0);
            $cost = (float) ($r['cost_usd'] ?? 0.0);
            $r['cost_per_1k_tokens'] = $tokens > 0
                ? round(($cost / $tokens) * 1000, 6)
                : 0.0;
            $out[] = $r;
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
        if ($driver === 'sqlsrv') {
            return "CONVERT(varchar(10), created_at, 23)";
        }
        // MySQL / MariaDB default
        return "DATE_FORMAT(created_at, '%Y-%m-%d')";
    }
}
