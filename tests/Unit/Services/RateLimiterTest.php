<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Services;

use DreamFactory\Core\AI\Services\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RateLimiter::shouldAllow — the pure decision logic that
 * decides whether a request is permitted given the current per-minute count
 * and the configured limit.
 *
 * The cache-mutating side of check() (Cache::put / Cache::increment) is
 * thin enough to not need its own unit test; it's exercised end-to-end any
 * time a rate-limited service handles traffic.
 */
class RateLimiterTest extends TestCase
{
    public function testZeroLimitIsTreatedAsUnlimited(): void
    {
        $this->assertTrue(RateLimiter::shouldAllow(0, 0));
        $this->assertTrue(RateLimiter::shouldAllow(999_999, 0));
    }

    public function testNegativeLimitIsTreatedAsUnlimited(): void
    {
        // Defensive: the column is declared unsigned but a stale config or a
        // direct DB tweak could deliver a negative. Treat as "no limit".
        $this->assertTrue(RateLimiter::shouldAllow(0, -1));
        $this->assertTrue(RateLimiter::shouldAllow(50, -100));
    }

    public function testAllowsBelowLimit(): void
    {
        $this->assertTrue(RateLimiter::shouldAllow(0, 60));
        $this->assertTrue(RateLimiter::shouldAllow(1, 60));
        $this->assertTrue(RateLimiter::shouldAllow(59, 60));
    }

    public function testRejectsAtAndAboveLimit(): void
    {
        // Boundary is exclusive: a limit of 60 permits exactly 60 calls
        // (counts 0–59), so at current=60 the next call must be rejected.
        $this->assertFalse(RateLimiter::shouldAllow(60, 60));
        $this->assertFalse(RateLimiter::shouldAllow(61, 60));
        $this->assertFalse(RateLimiter::shouldAllow(1_000_000, 60));
    }

    public function testLimitOfOnePermitsExactlyOneRequest(): void
    {
        // First call: current=0 → allowed.
        $this->assertTrue(RateLimiter::shouldAllow(0, 1));
        // Counter incremented to 1: next call must be rejected.
        $this->assertFalse(RateLimiter::shouldAllow(1, 1));
    }
}
