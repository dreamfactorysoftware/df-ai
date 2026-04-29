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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function errorMessageProvider(): array
    {
        return [
            'empty string'              => ['', 'unknown'],
            'verbose timeout'           => ['cURL request timed out after 30s', 'timeout'],
            'short timeout'             => ['Request timeout', 'timeout'],
            '429 numeric'               => ['HTTP 429: Too Many Requests', 'rate_limit'],
            'rate-limit hyphenated'     => ['Rate-limit exceeded for org', 'rate_limit'],
            'rate limit spaced'         => ['You hit the rate limit, slow down', 'rate_limit'],
            '401 numeric'               => ['HTTP 401 returned by provider', 'auth'],
            '403 numeric'               => ['HTTP 403 forbidden', 'auth'],
            'unauthorized prose'        => ['Unauthorized: bad token', 'auth'],
            'invalid api key'           => ['Invalid API key supplied', 'auth'],
            'authentication generic'    => ['Authentication failed', 'auth'],
            'model not found 404'       => ['Model claude-foo: 404 not found', 'model_not_found'],
            'model not found prose'     => ['model gpt-bar not found', 'model_not_found'],
            'context length'            => ['Maximum context length 8192 exceeded', 'context_overflow'],
            'too many tokens'           => ['too many tokens in prompt', 'context_overflow'],
            'maximum context'           => ['Reached maximum context window', 'context_overflow'],
            'connection refused'        => ['Connection refused on 127.0.0.1:8000', 'connectivity'],
            'cannot connect'            => ['Cannot connect to host', 'connectivity'],
            'dns resolution'            => ['Could not resolve host: api.example.com', 'connectivity'],
            '500 server'                => ['HTTP 500 internal server error', 'provider_5xx'],
            '503 server'                => ['HTTP 503 service unavailable', 'provider_5xx'],
            '400 bad request'           => ['HTTP 400 bad request body', 'provider_4xx'],
            '418 teapot'                => ['HTTP 418: i am a teapot', 'provider_4xx'],
            'truly unknown'             => ['Something exploded inside the planet core', 'other'],
        ];
    }

    /**
     * @dataProvider errorMessageProvider
     */
    public function testClassifyErrorBucketsCommonFailureShapes(string $msg, string $expected): void
    {
        $this->assertSame(
            $expected,
            UsageAggregator::classifyError($msg),
            "Expected '{$msg}' to classify as '{$expected}'"
        );
    }

    public function testClassifyErrorIsCaseInsensitive(): void
    {
        // Providers vary on casing — the lowercase normalization in
        // classifyError must keep the buckets stable.
        $this->assertSame('timeout', UsageAggregator::classifyError('TIMEOUT during call'));
        $this->assertSame('rate_limit', UsageAggregator::classifyError('Rate Limit hit'));
        $this->assertSame('auth', UsageAggregator::classifyError('UNAUTHORIZED.'));
    }

    public function testClassifyErrorPriorityResolvesAmbiguity(): void
    {
        // A 429 message is bucketed as rate_limit, not provider_4xx, even
        // though "429" matches the 4xx regex too — rate-limit detection runs
        // before the generic 4xx fallthrough.
        $this->assertSame(
            'rate_limit',
            UsageAggregator::classifyError('HTTP 429 too many requests')
        );
        // Same for 401 / 403 → auth, not provider_4xx.
        $this->assertSame('auth', UsageAggregator::classifyError('HTTP 401 unauthorized'));
        $this->assertSame('auth', UsageAggregator::classifyError('HTTP 403 forbidden'));
        // 500 doesn't accidentally trip the 4xx bucket.
        $this->assertSame('provider_5xx', UsageAggregator::classifyError('HTTP 500'));
    }

    public function testGroupErrorsReturnsEmptyForNoRows(): void
    {
        $this->assertSame([], UsageAggregator::groupErrors([], 0));
        $this->assertSame([], UsageAggregator::groupErrors([], 5));   // total>0 but no rows sampled
        $this->assertSame([], UsageAggregator::groupErrors([['error_message' => 'timeout']], 0));
    }

    public function testGroupErrorsCountsAndSortsByFrequency(): void
    {
        $rows = [
            ['error_message' => 'cURL timed out'],
            ['error_message' => 'cURL timed out'],
            ['error_message' => 'cURL timed out'],
            ['error_message' => 'HTTP 401 unauthorized'],
            ['error_message' => 'HTTP 500 server error'],
        ];

        $out = UsageAggregator::groupErrors($rows, 5);

        $this->assertSame(
            [
                ['class' => 'timeout', 'count' => 3],
                ['class' => 'auth', 'count' => 1],
                ['class' => 'provider_5xx', 'count' => 1],
            ],
            $out
        );
    }

    public function testGroupErrorsScalesUpWhenSampleSmallerThanTotal(): void
    {
        // Mimic the aggregate() path: errors=1000 but only 100 sampled.
        // Each bucketed count should scale up proportionally so the
        // dashboard's pie chart matches the headline error count.
        $rows = array_fill(0, 100, ['error_message' => 'cURL timed out']);
        $out = UsageAggregator::groupErrors($rows, 1000);

        $this->assertCount(1, $out);
        $this->assertSame('timeout', $out[0]['class']);
        $this->assertSame(1000, $out[0]['count']);
    }

    public function testGroupErrorsHandlesObjectShapedRows(): void
    {
        // The aggregate() path passes rows through Eloquent's ->toArray() so
        // they're associative arrays, but the helper accepts stdClass too
        // (older callers, raw selectRaw paths, etc.). Make sure we handle both.
        $rows = [
            (object) ['error_message' => 'HTTP 429 too many requests'],
            (object) ['error_message' => 'HTTP 429 too many requests'],
        ];

        $out = UsageAggregator::groupErrors($rows, 2);

        $this->assertSame([['class' => 'rate_limit', 'count' => 2]], $out);
    }

    public function testGroupErrorsHandlesNullErrorMessages(): void
    {
        // A row with status='error' but a null error_message bucket as
        // 'unknown'. Defensive against rows from before error_message was a
        // populated column.
        $rows = [
            ['error_message' => null],
            ['error_message' => 'cURL timed out'],
        ];

        $out = UsageAggregator::groupErrors($rows, 2);
        $byClass = array_column($out, 'count', 'class');

        $this->assertSame(1, $byClass['unknown']);
        $this->assertSame(1, $byClass['timeout']);
    }
}
