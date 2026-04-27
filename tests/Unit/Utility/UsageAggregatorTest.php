<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Utility;

use DreamFactory\Core\AI\Utility\UsageAggregator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UsageAggregator's pure-function helpers (period parsing,
 * SQL date-expression dispatch).
 *
 * The aggregate() method itself runs queries against ai_usage_log, so it's
 * exercised by the Integration/UsageEndpointSmokeTest.sh script — separately
 * from this file.
 */
class UsageAggregatorTest extends TestCase
{
    public function testParsePeriodHandlesDays(): void
    {
        $now = Carbon::create(2026, 4, 27, 12, 0, 0, 'UTC');

        $since = UsageAggregator::parsePeriod('7d', $now);
        $this->assertSame('2026-04-20T12:00:00+00:00', $since->toIso8601String());

        $since = UsageAggregator::parsePeriod('30d', $now);
        $this->assertSame('2026-03-28T12:00:00+00:00', $since->toIso8601String());

        $since = UsageAggregator::parsePeriod('90d', $now);
        $this->assertSame('2026-01-27T12:00:00+00:00', $since->toIso8601String());
    }

    public function testParsePeriodHandlesHours(): void
    {
        $now = Carbon::create(2026, 4, 27, 12, 0, 0, 'UTC');

        $since = UsageAggregator::parsePeriod('24h', $now);
        $this->assertSame('2026-04-26T12:00:00+00:00', $since->toIso8601String());

        $since = UsageAggregator::parsePeriod('1h', $now);
        $this->assertSame('2026-04-27T11:00:00+00:00', $since->toIso8601String());
    }

    public function testParsePeriodFallsBackToSevenDays(): void
    {
        $now = Carbon::create(2026, 4, 27, 12, 0, 0, 'UTC');

        // Garbage input should not throw.
        $this->assertSame(
            '2026-04-20T12:00:00+00:00',
            UsageAggregator::parsePeriod('garbage', $now)->toIso8601String()
        );
        $this->assertSame(
            '2026-04-20T12:00:00+00:00',
            UsageAggregator::parsePeriod('', $now)->toIso8601String()
        );
        $this->assertSame(
            '2026-04-20T12:00:00+00:00',
            UsageAggregator::parsePeriod('7days', $now)->toIso8601String()
        );
    }

    public function testParsePeriodWithoutNowDefaultsToCarbonNow(): void
    {
        // Without an injected clock, parsePeriod uses Carbon::now(). We just
        // assert it returns a Carbon instance in the past. (Don't pin to a
        // specific timestamp — the test would race the clock.)
        $since = UsageAggregator::parsePeriod('7d');
        $this->assertInstanceOf(Carbon::class, $since);
        $this->assertTrue($since->lessThan(Carbon::now()));
        $diffDays = abs(Carbon::now()->diffInDays($since, false));
        $this->assertEqualsWithDelta(7, $diffDays, 0.5);
    }

    public function testDateExpressionPerDriver(): void
    {
        $this->assertStringContainsString(
            'DATE_FORMAT',
            UsageAggregator::dateExpression('mysql')
        );
        $this->assertStringContainsString(
            'strftime',
            UsageAggregator::dateExpression('sqlite')
        );
        $this->assertStringContainsString(
            'to_char',
            UsageAggregator::dateExpression('pgsql')
        );
        // Unknown drivers fall back to MySQL syntax.
        $this->assertStringContainsString(
            'DATE_FORMAT',
            UsageAggregator::dateExpression('mariadb')
        );
        $this->assertStringContainsString(
            'DATE_FORMAT',
            UsageAggregator::dateExpression('something-weird')
        );
    }

    public function testDateExpressionAlwaysOperatesOnCreatedAt(): void
    {
        // Whichever driver: the column being grouped on must always be
        // created_at — a refactor that breaks this would silently drop the
        // time-series chart on the dashboard.
        foreach (['mysql', 'sqlite', 'pgsql', 'mariadb'] as $driver) {
            $expr = UsageAggregator::dateExpression($driver);
            $this->assertStringContainsString(
                'created_at',
                $expr,
                "dateExpression for {$driver} should reference created_at"
            );
        }
    }
}
