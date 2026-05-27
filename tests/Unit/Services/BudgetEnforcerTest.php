<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Services;

use DreamFactory\Core\AI\Models\AiBudget;
use DreamFactory\Core\AI\Services\BudgetEnforcer;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BudgetEnforcer's pure helpers.
 *
 * The DB-touching surfaces (`check`, `matchingBudgets`, `spendForBudget`)
 * are exercised end-to-end via the resource integration. What we pin
 * here is the period-window math + the dimension-description string,
 * which together drive both the cap check and the human-readable
 * error message a customer sees on a 429 budget rejection.
 */
class BudgetEnforcerTest extends TestCase
{
    public function testPeriodStartMonthlyAlignsToCalendar(): void
    {
        $now = Carbon::create(2026, 4, 17, 14, 32, 11, 'UTC');
        $start = BudgetEnforcer::periodStart('monthly', $now);
        $this->assertSame('2026-04-01T00:00:00+00:00', $start->toIso8601String());
    }

    public function testPeriodStartDailyAlignsToMidnight(): void
    {
        $now = Carbon::create(2026, 4, 17, 14, 32, 11, 'UTC');
        $start = BudgetEnforcer::periodStart('daily', $now);
        $this->assertSame('2026-04-17T00:00:00+00:00', $start->toIso8601String());
    }

    public function testPeriodStartHourlyAlignsToHour(): void
    {
        $now = Carbon::create(2026, 4, 17, 14, 32, 11, 'UTC');
        $start = BudgetEnforcer::periodStart('hourly', $now);
        $this->assertSame('2026-04-17T14:00:00+00:00', $start->toIso8601String());
    }

    public function testPeriodStartUnknownFallsBackToMonthly(): void
    {
        $now = Carbon::create(2026, 4, 17, 14, 32, 11, 'UTC');
        // Unknown period strings shouldn't crash — fall back to the
        // safest default (monthly = the most-restrictive period).
        $this->assertSame(
            '2026-04-01T00:00:00+00:00',
            BudgetEnforcer::periodStart('weekly', $now)->toIso8601String()
        );
        $this->assertSame(
            '2026-04-01T00:00:00+00:00',
            BudgetEnforcer::periodStart('', $now)->toIso8601String()
        );
    }

    public function testDescribeDimensionForEachType(): void
    {
        $cases = [
            ['global',   null, 'global AI spend'],
            ['service',  136,  'AI Connection #136'],
            ['user',     1,    'user #1'],
            ['role',     11,   'role #11'],
            ['app',      6,    'app #6'],
        ];
        foreach ($cases as [$type, $id, $expected]) {
            $b = new AiBudget();
            $b->setRawAttributes(['dimension_type' => $type, 'dimension_id' => $id], true);
            $this->assertSame($expected, BudgetEnforcer::describeDimension($b));
        }
    }

    public function testDescribeDimensionUnknownReturnsGenericLabel(): void
    {
        // Defensive: if an admin somehow gets an unknown dimension_type
        // into the table (manual SQL, future migration in flight), the
        // describer shouldn't crash.
        $b = new AiBudget();
        $b->setRawAttributes(['dimension_type' => 'mystery', 'dimension_id' => 99], true);
        $this->assertSame('AI spend', BudgetEnforcer::describeDimension($b));
    }
}
