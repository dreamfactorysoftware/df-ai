<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Services;

use DreamFactory\Core\AI\Models\AiBudget;
use DreamFactory\Core\AI\Services\BudgetAlerter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BudgetAlerter's pure helpers — payload shape +
 * threshold ordering.
 *
 * Webhook dispatch + the periodic-reset behaviour of `last_alerted_*_at`
 * are integration concerns (touch DB + outbound HTTP), exercised by
 * the resource-layer wiring.
 */
class BudgetAlerterTest extends TestCase
{
    public function testThresholdsOrderedHighToLow(): void
    {
        // Order matters — evaluateBudget walks high→low and returns
        // after the first match so we only fire the highest-crossed
        // threshold per call. A reorder here breaks the contract that
        // says "crossing 100% does NOT also fire a 50% alert."
        $thresholds = BudgetAlerter::thresholds();
        $this->assertCount(3, $thresholds);
        $this->assertSame([1.0, 'last_alerted_100_at'], $thresholds[0]);
        $this->assertSame([0.8, 'last_alerted_80_at'], $thresholds[1]);
        $this->assertSame([0.5, 'last_alerted_50_at'], $thresholds[2]);
    }

    public function testPayloadShapeIncludesAllFieldsCustomersDashboardOn(): void
    {
        $b = new AiBudget();
        $b->setRawAttributes([
            'id'             => 42,
            'label'          => 'Marketing monthly cap',
            'dimension_type' => 'role',
            'dimension_id'   => 11,
            'period'         => 'monthly',
            'budget_usd'     => 500.0,
            'hard_stop'      => true,
        ], true);

        $payload = BudgetAlerter::buildPayload($b, 0.8, 412.50, 500.0);

        // Top-level shape — pinned because customer alerting tools
        // (PagerDuty, OpsGenie, Slack) parse on these field names.
        $this->assertSame('ai.budget.threshold_crossed', $payload['event']);
        $this->assertSame(80, $payload['threshold_pct']);

        // budget block.
        $this->assertSame(42, $payload['budget']['id']);
        $this->assertSame('Marketing monthly cap', $payload['budget']['label']);
        $this->assertSame('role', $payload['budget']['dimension_type']);
        $this->assertSame(11, $payload['budget']['dimension_id']);
        $this->assertSame('monthly', $payload['budget']['period']);
        $this->assertSame(500.0, $payload['budget']['budget_usd']);
        $this->assertTrue($payload['budget']['hard_stop']);

        // spend block — round to 6 decimals (matches dashboard precision).
        $this->assertEqualsWithDelta(412.5, $payload['spend']['spend_usd'], 1e-6);
        $this->assertEqualsWithDelta(87.5, $payload['spend']['remaining_usd'], 1e-6);
        $this->assertEqualsWithDelta(0.825, $payload['spend']['utilization'], 1e-4);

        // Timestamp present.
        $this->assertArrayHasKey('@timestamp', $payload);
    }

    public function testPayloadFallsBackToDescribedDimensionWhenNoLabel(): void
    {
        $b = new AiBudget();
        $b->setRawAttributes([
            'id'             => 7,
            'label'          => null,
            'dimension_type' => 'service',
            'dimension_id'   => 136,
            'period'         => 'monthly',
            'budget_usd'     => 50.0,
            'hard_stop'      => false,
        ], true);

        $payload = BudgetAlerter::buildPayload($b, 0.5, 26.0, 50.0);
        $this->assertSame('AI Connection #136', $payload['budget']['label']);
    }

    public function testPayloadHandlesGlobalBudgetWithNullDimensionId(): void
    {
        $b = new AiBudget();
        $b->setRawAttributes([
            'id'             => 1,
            'label'          => 'Org-wide cap',
            'dimension_type' => 'global',
            'dimension_id'   => null,
            'period'         => 'monthly',
            'budget_usd'     => 5000.0,
            'hard_stop'      => false,
        ], true);

        $payload = BudgetAlerter::buildPayload($b, 1.0, 5234.0, 5000.0);
        $this->assertNull($payload['budget']['dimension_id']);
        // Spend > budget → remaining = 0, not negative.
        $this->assertSame(0.0, $payload['spend']['remaining_usd']);
        // Utilization can exceed 1.0 (handy signal for "by how much").
        $this->assertEqualsWithDelta(1.0468, $payload['spend']['utilization'], 1e-4);
    }

    public function testPayloadAvoidsDivByZeroOnZeroBudget(): void
    {
        // A misconfigured 0 budget shouldn't blow up the alerter. We
        // skip evaluation in evaluateBudget(), but buildPayload should
        // still produce sane output if called directly.
        $b = new AiBudget();
        $b->setRawAttributes([
            'id'             => 1,
            'label'          => 'broken',
            'dimension_type' => 'global',
            'dimension_id'   => null,
            'period'         => 'monthly',
            'budget_usd'     => 0.0,
            'hard_stop'      => false,
        ], true);

        $payload = BudgetAlerter::buildPayload($b, 1.0, 1.0, 0.0);
        // Utilization handled by max(budget, 1e-9) — should be a huge
        // finite number, not Inf or NaN.
        $this->assertIsFloat($payload['spend']['utilization']);
        $this->assertTrue(is_finite($payload['spend']['utilization']));
    }
}
