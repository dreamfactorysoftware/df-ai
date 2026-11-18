<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Services;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use DreamFactory\Core\AI\Services\BudgetEnforcer;
use DreamFactory\Core\AI\Tests\Support\BudgetDatabase;
use DreamFactory\Core\Exceptions\TooManyRequestsException;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/BudgetDatabase.php';

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
        // The app an API key resolves to is a numeric string in the session.
        $this->session = ['app.id' => '9'];
        $this->budget(['dimension_type' => 'app', 'dimension_id' => 9, 'budget_usd' => 1]);
        $this->spend(2, '2026-11-17 14:00:00', ['user_id' => null, 'role_id' => null]);

        $this->assertTrue(BudgetEnforcer::isBlocked(1));
    }

    public function testSessionIdsHeldAsStringsAreCheckedAsInts(): void
    {
        // A user signed in with an API key: any of the three ids can be a
        // string. Under the cap the call goes through, over it it is a 429.
        $this->session = ['user.id' => '5', 'role.id' => '7', 'app.id' => '9'];
        $this->assertSame([5, 7, 9], BudgetEnforcer::sessionAttribution());

        $this->budget(['dimension_type' => 'app', 'dimension_id' => 9, 'budget_usd' => 1, 'label' => 'Portal app']);
        BudgetEnforcer::enforce(1);
        BudgetEnforcer::checkSession(1);
        $this->assertFalse(BudgetEnforcer::isBlocked(1));

        $this->spend(2, '2026-11-17 14:00:00');
        try {
            BudgetEnforcer::enforce(1);
            $this->fail('expected a refusal');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertStringContainsString('Portal app', $e->getMessage());
        }
        try {
            BudgetEnforcer::checkSession(1);
            $this->fail('expected a refusal');
        } catch (AiProviderException $e) {
            $this->assertSame(429, $e->getHttpStatus());
        }
        $this->assertTrue(BudgetEnforcer::isBlocked(1));
    }

    public function testASessionIdThatIsNotAnIdIsTreatedAsAbsent(): void
    {
        $this->session = ['user.id' => '', 'role.id' => 'none', 'app.id' => null];
        $this->assertSame([null, null, null], BudgetEnforcer::sessionAttribution());

        $this->budget(['dimension_type' => 'global', 'budget_usd' => 1]);
        $this->spend(2, '2026-11-17 14:00:00');
        $this->assertTrue(BudgetEnforcer::isBlocked(1), 'the global budget still applies');
    }

    public function testNoCallerPassesSessionValuesToTheBudgetApi(): void
    {
        // check(), matchingBudgets() and afterCall() take ?int ids under
        // strict_types. Session values are untyped, so callers go through
        // checkSession() / enforce() / isBlocked() / afterRequest(), which
        // read the session via sessionAttribution(). No app container here,
        // so this is a source check, like the Data Chat rate-limit tests.
        $src = dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        $calls = 0;
        $offenders = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all(
                '/(?:BudgetEnforcer|BudgetAlerter|self|static)::(?:check|matchingBudgets|afterCall)\s*(\((?:[^()]++|(?1))*\))/',
                $source,
                $matches,
            );
            foreach ($matches[0] as $call) {
                $calls++;
                if (str_contains($call, 'Session::') || str_contains($call, 'session(')) {
                    $offenders[] = $file->getFilename() . ': ' . preg_replace('/\s+/', ' ', $call);
                }
            }
        }

        $this->assertGreaterThan(0, $calls, 'no budget API calls found; is the pattern stale?');
        $this->assertSame([], $offenders);
    }
}
