<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Services;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use DreamFactory\Core\AI\Services\BudgetEnforcer;
use DreamFactory\Core\AI\Tests\Support\BudgetDatabase;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/BudgetDatabase.php';

/**
 * The enforcement rules, run against real queries on an in-memory database.
 *
 * The rule when budgets collide: every active budget that matches the
 * request applies, and any one of them with hard_stop at or over its cap
 * refuses the request. A budget without hard_stop never refuses.
 */
class BudgetEnforcerRulesTest extends TestCase
{
    use BudgetDatabase;

    private const SERVICE = 1;
    private const USER = 5;
    private const ROLE = 7;
    private const APP = 9;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBudgetDatabase();
        Carbon::setTestNow(Carbon::create(2026, 11, 17, 14, 30, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function refused(?int $role = self::ROLE, ?int $user = self::USER, ?int $app = self::APP): bool
    {
        try {
            BudgetEnforcer::check(self::SERVICE, $user, $role, $app);

            return false;
        } catch (AiProviderException $e) {
            $this->assertSame(429, $e->getHttpStatus());

            return true;
        }
    }

    public function testBelowTheCapTheCallIsAllowed(): void
    {
        $this->budget(['dimension_type' => 'role', 'dimension_id' => self::ROLE, 'budget_usd' => 10]);
        $this->spend(9.999999, '2026-11-17 14:00:00');

        $this->assertFalse($this->refused());
    }

    public function testAtTheCapTheCallIsRefused(): void
    {
        $this->budget(['dimension_type' => 'role', 'dimension_id' => self::ROLE, 'budget_usd' => 10]);
        $this->spend(6, '2026-11-02 09:00:00');
        $this->spend(4, '2026-11-17 14:00:00');

        $this->assertTrue($this->refused());
    }

    public function testASoftBudgetNeverRefuses(): void
    {
        $this->budget(['dimension_type' => 'role', 'dimension_id' => self::ROLE, 'budget_usd' => 1, 'hard_stop' => false]);
        $this->spend(500, '2026-11-17 14:00:00');

        $this->assertFalse($this->refused());
    }

    public function testABlockedRoleDoesNotAffectAnotherRole(): void
    {
        $this->budget(['dimension_type' => 'role', 'dimension_id' => self::ROLE, 'budget_usd' => 1]);
        $this->spend(2, '2026-11-17 14:00:00');

        $this->assertTrue($this->refused(self::ROLE));
        $this->assertFalse($this->refused(8));
    }

    public function testAnyHardStopAtItsCapWinsOverBudgetsWithRoom(): void
    {
        // Role and connection both have room. The global hard stop is spent.
        $this->budget(['dimension_type' => 'role', 'dimension_id' => self::ROLE, 'budget_usd' => 100]);
        $this->budget(['dimension_type' => 'service', 'dimension_id' => self::SERVICE, 'budget_usd' => 100]);
        $this->budget(['dimension_type' => 'global', 'budget_usd' => 5]);
        $this->spend(3, '2026-11-17 14:00:00');
        $this->spend(3, '2026-11-17 14:05:00', ['role_id' => 8, 'user_id' => 6, 'app_id' => 10, 'service_id' => 2]);

        $this->assertTrue($this->refused(), 'global spend of 6 is over the global cap of 5');
    }

    public function testASoftBudgetOverItsCapDoesNotBlockWhenTheHardStopsHaveRoom(): void
    {
        $this->budget(['dimension_type' => 'global', 'budget_usd' => 1, 'hard_stop' => false]);
        $this->budget(['dimension_type' => 'role', 'dimension_id' => self::ROLE, 'budget_usd' => 100]);
        $this->spend(50, '2026-11-17 14:00:00');

        $this->assertFalse($this->refused());
    }

    public function testEachScopeCountsOnlyItsOwnSpend(): void
    {
        foreach (['service' => self::SERVICE, 'user' => self::USER, 'app' => self::APP] as $type => $id) {
            Capsule::table('ai_budget')->delete();
            Capsule::table('ai_usage_log')->delete();
            $this->budget(['dimension_type' => $type, 'dimension_id' => $id, 'budget_usd' => 1]);

            // Spend by a different service, user, role and app.
            $this->spend(50, '2026-11-17 14:00:00', ['service_id' => 2, 'user_id' => 6, 'role_id' => 8, 'app_id' => 10]);
            $this->assertFalse($this->refused(), "{$type}: other spend must not count");

            $this->spend(1, '2026-11-17 14:10:00');
            $this->assertTrue($this->refused(), "{$type}: own spend must count");
        }
    }

    public function testAnInactiveBudgetDoesNotRefuse(): void
    {
        $this->budget(['dimension_type' => 'global', 'budget_usd' => 1, 'is_active' => false]);
        $this->spend(50, '2026-11-17 14:00:00');

        $this->assertFalse($this->refused());
    }

    public function testADeletedBudgetStopsApplying(): void
    {
        $id = $this->budget(['dimension_type' => 'global', 'budget_usd' => 1]);
        $this->spend(50, '2026-11-17 14:00:00');
        $this->assertTrue($this->refused());

        Capsule::table('ai_budget')->where('id', $id)->delete();
        $this->assertFalse($this->refused());
    }

    public function testACallerWithNoRoleIsNotHeldToARoleBudget(): void
    {
        // A full administrator has no role id. Global, service, user and app
        // budgets still apply to that call.
        $this->budget(['dimension_type' => 'role', 'dimension_id' => self::ROLE, 'budget_usd' => 1]);
        $this->spend(50, '2026-11-17 14:00:00');

        $this->assertFalse($this->refused(null));
    }

    public function testSpendBeforeThePeriodStartIsNotCounted(): void
    {
        $cases = [
            'monthly' => '2026-10-31 23:59:59',
            'daily'   => '2026-11-16 23:59:59',
            'hourly'  => '2026-11-17 13:59:59',
        ];
        foreach ($cases as $period => $justBefore) {
            Capsule::table('ai_budget')->delete();
            Capsule::table('ai_usage_log')->delete();
            $this->budget(['dimension_type' => 'global', 'budget_usd' => 1, 'period' => $period]);

            $this->spend(50, $justBefore);
            $this->assertFalse($this->refused(), "{$period}: spend before the window must not count");
        }
    }

    public function testSpendAtThePeriodStartIsCounted(): void
    {
        $cases = [
            'monthly' => '2026-11-01 00:00:00',
            'daily'   => '2026-11-17 00:00:00',
            'hourly'  => '2026-11-17 14:00:00',
        ];
        foreach ($cases as $period => $atStart) {
            Capsule::table('ai_budget')->delete();
            Capsule::table('ai_usage_log')->delete();
            $this->budget(['dimension_type' => 'global', 'budget_usd' => 1, 'period' => $period]);

            $this->spend(1, $atStart);
            $this->assertTrue($this->refused(), "{$period}: spend at the window start must count");
        }
    }

    public function testTheCapLiftsWhenThePeriodRollsOver(): void
    {
        $this->budget(['dimension_type' => 'global', 'budget_usd' => 1, 'period' => 'hourly']);
        $this->spend(2, '2026-11-17 14:10:00');
        $this->assertTrue($this->refused());

        Carbon::setTestNow(Carbon::create(2026, 11, 17, 15, 0, 0, 'UTC'));
        $this->assertFalse($this->refused());
    }

    public function testErrorRowsCostNothingAndPartialRowsCount(): void
    {
        $this->budget(['dimension_type' => 'global', 'budget_usd' => 1]);
        $this->spend(0, '2026-11-17 14:00:00', ['status' => 'error']);
        $this->assertFalse($this->refused());

        $this->spend(1, '2026-11-17 14:01:00', ['status' => 'partial']);
        $this->assertTrue($this->refused());
    }

    public function testTheRefusalNamesTheBudget(): void
    {
        $this->budget(['dimension_type' => 'global', 'budget_usd' => 1, 'label' => 'Platform total']);
        $this->spend(2.5, '2026-11-17 14:00:00');

        try {
            BudgetEnforcer::check(self::SERVICE, self::USER, self::ROLE, self::APP);
            $this->fail('expected a refusal');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('Platform total', $e->getMessage());
            $this->assertFalse($e->isRetryable(), 'a refusal must not send the call to a fallback connection');
        }
    }
}
