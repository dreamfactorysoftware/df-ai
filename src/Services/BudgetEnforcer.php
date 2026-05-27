<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Services;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use DreamFactory\Core\AI\Models\AiBudget;
use DreamFactory\Core\AI\Models\AiUsageLog;
use Illuminate\Support\Carbon;

/**
 * Pre-call budget enforcement for the AI Gateway.
 *
 * Looks up every active budget that matches the (service, role, user,
 * app) attribution of an incoming request. Sums spend in each budget's
 * period from ai_usage_log. If any budget with hard_stop=true is at or
 * above its cap, throws an AiProviderException(429) with a clear
 * "budget exceeded" message.
 *
 * Architecture parallels {@see RateLimiter}: static API, throws on
 * deny, called once per request from the resource layer immediately
 * after the rate-limit check.
 *
 * **What this is NOT:**
 *   - It does not deduct from a counter. It re-aggregates from
 *     ai_usage_log on every check. For the volumes regulated buyers
 *     run (10s-1000s of req/min, not 100K), this is fine. Higher-volume
 *     installs can layer a Redis-backed counter under the same API.
 *
 *   - It does not gate on per-call cost (the AI hasn't returned yet,
 *     so we don't know the cost). It only gates on cumulative-spend-
 *     to-date crossing the cap. If a single call would push us over,
 *     it goes through; the NEXT call is the one that gets blocked.
 *     This matches how every credit-card-limit system works.
 */
class BudgetEnforcer
{
    /**
     * Throw if any matching hard-stop budget is at or above its cap.
     *
     * @param int   $serviceId AI Connection service id
     * @param ?int  $userId    DF user id (null when anonymous)
     * @param ?int  $roleId    DF role id (null when no role applied)
     * @param ?int  $appId     DF app id (null when no app)
     * @throws AiProviderException
     */
    public static function check(int $serviceId, ?int $userId, ?int $roleId, ?int $appId): void
    {
        foreach (self::matchingBudgets($serviceId, $userId, $roleId, $appId) as $budget) {
            if (!$budget->hard_stop) {
                continue; // observability-only budget; alerts fire elsewhere
            }
            $spend = self::spendForBudget($budget);
            if ($spend >= (float) $budget->budget_usd) {
                $label = $budget->label ?: self::describeDimension($budget);
                // Throw a 429-shaped exception with the budget-specific
                // message. retryable=false so FallbackChain does NOT
                // route to a sibling AI Connection and burn that budget
                // instead — hitting the cap is a deliberate stop, not
                // an upstream hiccup.
                throw new AiProviderException(
                    message: sprintf(
                        'AI budget exceeded for %s: $%.4f spent of $%.2f budgeted (period=%s).',
                        $label,
                        $spend,
                        (float) $budget->budget_usd,
                        $budget->period,
                    ),
                    code: 429,
                    httpStatus: 429,
                    retryable: false,
                );
            }
        }
    }

    /**
     * Return every active budget that applies to the given attribution.
     *
     * @return array<int, AiBudget>
     */
    public static function matchingBudgets(int $serviceId, ?int $userId, ?int $roleId, ?int $appId): array
    {
        $query = AiBudget::query()->where('is_active', true);

        // We want budgets that match ANY of:
        //   global (no dimension_id)
        //   service:serviceId
        //   user:userId      (if userId != null)
        //   role:roleId      (if roleId != null)
        //   app:appId        (if appId != null)
        $query->where(function ($q) use ($serviceId, $userId, $roleId, $appId) {
            $q->where('dimension_type', 'global');
            $q->orWhere(function ($q2) use ($serviceId) {
                $q2->where('dimension_type', 'service')->where('dimension_id', $serviceId);
            });
            if ($userId !== null) {
                $q->orWhere(function ($q2) use ($userId) {
                    $q2->where('dimension_type', 'user')->where('dimension_id', $userId);
                });
            }
            if ($roleId !== null) {
                $q->orWhere(function ($q2) use ($roleId) {
                    $q2->where('dimension_type', 'role')->where('dimension_id', $roleId);
                });
            }
            if ($appId !== null) {
                $q->orWhere(function ($q2) use ($appId) {
                    $q2->where('dimension_type', 'app')->where('dimension_id', $appId);
                });
            }
        });

        return $query->get()->all();
    }

    /**
     * Sum cost_usd from ai_usage_log within the budget's period for
     * its dimension. Pure / exposed for testing the period-window math.
     */
    public static function spendForBudget(AiBudget $budget, ?Carbon $now = null): float
    {
        $now = $now ?? Carbon::now();
        $start = self::periodStart($budget->period, $now);

        $query = AiUsageLog::query()->where('created_at', '>=', $start);

        switch ($budget->dimension_type) {
            case 'global':
                break; // no dimension filter
            case 'service':
                $query->where('service_id', $budget->dimension_id);
                break;
            case 'user':
                $query->where('user_id', $budget->dimension_id);
                break;
            case 'role':
                $query->where('role_id', $budget->dimension_id);
                break;
            case 'app':
                $query->where('app_id', $budget->dimension_id);
                break;
        }

        return (float) $query->sum('cost_usd');
    }

    /**
     * Compute the start-of-period for a given period name. Pure /
     * testable. 'monthly' uses calendar month (matches how
     * UsageAggregator::budgetStatus already reasons about budgets).
     */
    public static function periodStart(string $period, Carbon $now): Carbon
    {
        return match ($period) {
            'hourly'  => $now->copy()->startOfHour(),
            'daily'   => $now->copy()->startOfDay(),
            default   => $now->copy()->startOfMonth(),
        };
    }

    /**
     * Human-friendly description of a budget for error / alert messages.
     */
    public static function describeDimension(AiBudget $budget): string
    {
        return match ($budget->dimension_type) {
            'global'  => 'global AI spend',
            'service' => "AI Connection #{$budget->dimension_id}",
            'user'    => "user #{$budget->dimension_id}",
            'role'    => "role #{$budget->dimension_id}",
            'app'     => "app #{$budget->dimension_id}",
            default   => 'AI spend',
        };
    }
}
