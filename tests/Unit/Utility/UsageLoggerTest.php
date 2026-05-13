<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Utility;

use DreamFactory\Core\AI\Utility\UsageLogger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UsageLogger's request-id management — the pure side of the
 * class that doesn't touch the database, Session, or the Cache facade.
 *
 * The full write-path (logSuccess/logError → AiUsageLog::create) is exercised
 * end-to-end by the Integration/UsageEndpointSmokeTest.sh script, since it
 * pulls in Session, the AiUsageLog Eloquent model, and a configured DB
 * connection.
 */
class UsageLoggerTest extends TestCase
{
    /**
     * The static request-id is shared across the class. Every test must start
     * from a clean slate so generation order doesn't leak between tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Reflection reset: the static is private and there's no public clear.
        $ref = new \ReflectionProperty(UsageLogger::class, 'requestId');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    public function testRequestIdGeneratesAUuidWhenNoneSet(): void
    {
        $id = UsageLogger::requestId();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id,
            'requestId() must return a UUID-shaped string when none has been set'
        );
    }

    public function testRequestIdIsStableWithinARequest(): void
    {
        // The whole point of the static is correlation across multiple
        // AiUsageLog rows in the same HTTP request — repeat calls must
        // return the same value.
        $first = UsageLogger::requestId();
        $second = UsageLogger::requestId();
        $third = UsageLogger::requestId();

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    public function testSetRequestIdOverridesGeneratedValue(): void
    {
        $custom = 'trace-abc123';
        UsageLogger::setRequestId($custom);

        $this->assertSame($custom, UsageLogger::requestId());
        // Subsequent reads must keep returning the override, not regenerate.
        $this->assertSame($custom, UsageLogger::requestId());
    }

    public function testSetRequestIdAfterAutoGenerationOverridesIt(): void
    {
        // Auto-generate first, then override (e.g. an inbound trace header
        // was found on a later middleware than the one that triggered logging).
        $generated = UsageLogger::requestId();
        UsageLogger::setRequestId('trace-override');

        $this->assertNotSame($generated, UsageLogger::requestId());
        $this->assertSame('trace-override', UsageLogger::requestId());
    }

    public function testFreshGenerationsAcrossRequestsAreUnique(): void
    {
        // Simulate two PHP requests by clearing the static between them.
        $first = UsageLogger::requestId();

        $ref = new \ReflectionProperty(UsageLogger::class, 'requestId');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $second = UsageLogger::requestId();

        $this->assertNotSame($first, $second, 'Distinct PHP requests must get distinct request ids');
    }
}
