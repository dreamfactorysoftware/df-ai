<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Services;

use DreamFactory\Core\AI\Models\AiBudget;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Post-call budget threshold detection + webhook dispatch.
 *
 * Runs after every successful AI call from the resource layer. For
 * each budget that applies to the request, computes the new spend-to-
 * budget ratio. When the ratio crosses a threshold (50% / 80% / 100%)
 * for the first time in the current period, fires the budget's
 * configured webhook with a structured payload + updates the
 * `last_alerted_*_at` timestamp so the same threshold doesn't re-fire
 * on every subsequent call.
 *
 * Threshold reset: the `last_alerted_*_at` columns are cleared lazily
 * — when we see a budget whose `last_alerted_X_at` is from a previous
 * period (older than current period start), we treat the threshold as
 * un-fired and re-evaluate. This avoids needing a cron job.
 *
 * Best-effort dispatch — webhook failures are Log::warning, never
 * surfaced to the customer's AI request.
 */
class BudgetAlerter
{
    private static ?Client $client = null;

    /**
     * After a successful call, recompute spend for matching budgets
     * and fire any newly-crossed threshold webhooks.
     */
    public static function afterCall(int $serviceId, ?int $userId, ?int $roleId, ?int $appId): void
    {
        $now = Carbon::now();
        foreach (BudgetEnforcer::matchingBudgets($serviceId, $userId, $roleId, $appId) as $budget) {
            self::evaluateBudget($budget, $now);
        }
    }

    /**
     * For one budget, check each threshold in order and fire if newly
     * crossed. Pure-ish (touches DB to update last_alerted_*_at and
     * fires webhooks) — exposed so a unit test can pass an in-memory
     * AiBudget instance directly.
     */
    public static function evaluateBudget(AiBudget $budget, ?Carbon $now = null): void
    {
        if (!$budget->is_active || empty($budget->alert_webhook_url)) {
            return; // Nothing to alert to.
        }

        $now = $now ?? Carbon::now();
        $spend = BudgetEnforcer::spendForBudget($budget, $now);
        $cap = (float) $budget->budget_usd;
        if ($cap <= 0) {
            return; // Nonsensical config; skip.
        }
        $ratio = $spend / $cap;
        $periodStart = BudgetEnforcer::periodStart($budget->period, $now);

        // Walk thresholds high → low. The first one matched is the one
        // we evaluate; lower thresholds would have already been crossed
        // and alerted on a previous request.
        foreach (self::thresholds() as [$pct, $column]) {
            if ($ratio < $pct) {
                continue;
            }
            $last = $budget->{$column};
            if ($last === null || $last->lt($periodStart)) {
                self::fireAlert($budget, $pct, $spend, $cap);
                $budget->{$column} = $now;
                $budget->save();
            }
            return; // Only fire the highest crossed threshold per call.
        }
    }

    /**
     * Threshold definitions: ordered high → low so we skip cheaper
     * checks once a higher one matches.
     *
     * @return array<int, array{0: float, 1: string}>
     */
    public static function thresholds(): array
    {
        return [
            [1.0, 'last_alerted_100_at'],
            [0.8, 'last_alerted_80_at'],
            [0.5, 'last_alerted_50_at'],
        ];
    }

    /**
     * Build the webhook payload for a threshold crossing. Pure / testable.
     *
     * @return array<string, mixed>
     */
    public static function buildPayload(AiBudget $budget, float $thresholdPct, float $spendUsd, float $budgetUsd): array
    {
        return [
            'event'           => 'ai.budget.threshold_crossed',
            'threshold_pct'   => (int) round($thresholdPct * 100),
            'budget' => [
                'id'             => (int) $budget->id,
                'label'          => $budget->label ?: BudgetEnforcer::describeDimension($budget),
                'dimension_type' => $budget->dimension_type,
                'dimension_id'   => $budget->dimension_id !== null ? (int) $budget->dimension_id : null,
                'period'         => $budget->period,
                'budget_usd'     => $budgetUsd,
                'hard_stop'      => (bool) $budget->hard_stop,
            ],
            'spend' => [
                'spend_usd'    => round($spendUsd, 6),
                'remaining_usd'=> round(max(0, $budgetUsd - $spendUsd), 6),
                'utilization'  => round($spendUsd / max($budgetUsd, 1e-9), 4),
            ],
            '@timestamp' => Carbon::now()->toIso8601String(),
        ];
    }

    private static function fireAlert(AiBudget $budget, float $pct, float $spend, float $cap): void
    {
        $payload = self::buildPayload($budget, $pct, $spend, $cap);

        $headers = ['Content-Type' => 'application/json'];
        if (!empty($budget->alert_webhook_auth_header)) {
            $headers['Authorization'] = (string) $budget->alert_webhook_auth_header;
        }

        try {
            self::client()->request('POST', (string) $budget->alert_webhook_url, [
                'json'    => $payload,
                'headers' => $headers,
                'timeout' => 5,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI budget alert webhook failed: ' . $e->getMessage(), [
                'budget_id' => $budget->id,
                'threshold' => $pct,
            ]);
        }
    }

    private static function client(): Client
    {
        if (self::$client === null) {
            self::$client = new Client(['timeout' => 5]);
        }
        return self::$client;
    }
}
