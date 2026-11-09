<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Services;

use DreamFactory\Core\AI\Services\BudgetEnforcer;
use DreamFactory\Core\AI\Tests\Support\BudgetDatabase;
use DreamFactory\Core\Exceptions\TooManyRequestsException;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * The REST entry points: enforce() and isBlocked() check the calling
 * session, which is the same attribution UsageLogger writes on the row.
 */
class BudgetEnforcerSessionTest extends TestCase
{
    use BudgetDatabase;

    private ?Container $previousContainer = null;

    /** @var array<string, mixed> the calling session */
    private array $session = ['user.id' => 5, 'role.id' => 7, 'app.id' => 9];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBudgetDatabase();
        Carbon::setTestNow(Carbon::create(2026, 11, 17, 14, 30, 0, 'UTC'));

        // df-core's Session uses the application's global facade alias.
        if (!class_exists('Session', false)) {
            class_alias(\Illuminate\Support\Facades\Session::class, 'Session');
        }

        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('session', new class($this->session) {
            public function __construct(private array &$values)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }
        });
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testEnforceRefusesTheSessionRoleWithHttp429(): void
    {
        $this->budget(['dimension_type' => 'role', 'dimension_id' => 7, 'budget_usd' => 1, 'label' => 'Analysts']);
        $this->spend(2, '2026-11-17 14:00:00');

        try {
            BudgetEnforcer::enforce(1);
            $this->fail('expected a refusal');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertStringContainsString('Analysts', $e->getMessage());
        }
        $this->assertTrue(BudgetEnforcer::isBlocked(1));
    }

    public function testAnotherRoleInTheSessionIsNotRefused(): void
    {
        $this->budget(['dimension_type' => 'role', 'dimension_id' => 7, 'budget_usd' => 1]);
        $this->spend(2, '2026-11-17 14:00:00');
        $this->session['role.id'] = 8;

        BudgetEnforcer::enforce(1);
        $this->assertFalse(BudgetEnforcer::isBlocked(1));
    }

    public function testIsBlockedLooksAtTheConnectionItIsAskedAbout(): void
    {
        // FallbackChain asks about each fallback connection before using it.
        $this->budget(['dimension_type' => 'service', 'dimension_id' => 2, 'budget_usd' => 1]);
        $this->spend(2, '2026-11-17 14:00:00', ['service_id' => 2]);

        $this->assertFalse(BudgetEnforcer::isBlocked(1), 'the primary has no cap');
        $this->assertTrue(BudgetEnforcer::isBlocked(2), 'the capped fallback must be skipped');
    }

    public function testAnApiKeyOnlyCallIsHeldToAppAndGlobalBudgets(): void
    {
        $this->session = ['app.id' => 9];
        $this->budget(['dimension_type' => 'app', 'dimension_id' => 9, 'budget_usd' => 1]);
        $this->spend(2, '2026-11-17 14:00:00', ['user_id' => null, 'role_id' => null]);

        $this->assertTrue(BudgetEnforcer::isBlocked(1));
    }
}
