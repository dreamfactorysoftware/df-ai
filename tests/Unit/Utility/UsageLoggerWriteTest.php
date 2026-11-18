<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Utility;

use DreamFactory\Core\AI\Utility\UsageLogger;
use DreamFactory\Core\AI\Utility\UsageRates;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * The write path of UsageLogger against a database that refuses what a
 * strict MySQL, PostgreSQL or SQL Server refuses. SQLite does not enforce
 * column widths, so the test table carries CHECK constraints that do.
 *
 * A refused insert is swallowed by design: the AI call already happened.
 * So every row a caller can make the database refuse is spend that no
 * budget counts.
 */
class UsageLoggerWriteTest extends TestCase
{
    private ?Container $previousContainer = null;

    /** @var array<int, string> what the code under test logged */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not loaded.');
        }

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::connection()->statement(<<<'SQL'
            CREATE TABLE ai_usage_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                service_id INTEGER NOT NULL,
                user_id INTEGER NULL,
                role_id INTEGER NULL,
                app_id INTEGER NULL,
                resource TEXT NOT NULL CHECK (length(resource) <= 50),
                provider TEXT NOT NULL CHECK (length(provider) <= 50),
                model TEXT NOT NULL CHECK (length(model) <= 200),
                input_tokens INTEGER NOT NULL DEFAULT 0,
                output_tokens INTEGER NOT NULL DEFAULT 0,
                tool_call_count INTEGER NOT NULL DEFAULT 0,
                cost_usd NUMERIC NOT NULL DEFAULT 0 CHECK (cost_usd >= 0 AND cost_usd < 10000),
                latency_ms INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'success' CHECK (length(status) <= 20),
                error_message TEXT NULL,
                request_id TEXT NULL CHECK (length(request_id) <= 36),
                -- The hour a daylight saving zone skips: a strict database
                -- refuses a timestamp inside it.
                created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP
                    CHECK (created_at NOT LIKE '2027-03-14 02:%')
            )
        SQL);
        Capsule::connection()->statement(
            'CREATE TABLE ai_connection_config (service_id INTEGER PRIMARY KEY, model_rates TEXT NULL, '
            . 'cost_per_1k_input NUMERIC NULL, cost_per_1k_output NUMERIC NULL)'
        );
        Capsule::table('ai_connection_config')->insert([
            'service_id' => 1, 'cost_per_1k_input' => 1.0, 'cost_per_1k_output' => 2.0,
        ]);

        // UsageLogger and df-core's Session use the application's global
        // facade aliases, which a bare test process does not have.
        foreach (['Log', 'Session'] as $alias) {
            if (!class_exists($alias, false)) {
                class_alias("Illuminate\\Support\\Facades\\{$alias}", $alias);
            }
        }

        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('config', new Repository(['df-ai' => ['usage_logging' => ['enabled' => true]]]));
        $this->logged = [];
        $container->instance('log', new class($this->logged) extends \Psr\Log\AbstractLogger {
            public function __construct(private array &$lines)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = "{$level}: {$message}";
            }
        });
        $container->instance('session', new class {
            public function get(string $key, mixed $default = null): mixed
            {
                return ['user.id' => 5, 'role.id' => 7, 'app.id' => 9][$key] ?? $default;
            }
        });
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        UsageRates::flushCache();
        $this->resetRequestId();
        Carbon::setTestNow(Carbon::create(2026, 11, 17, 14, 30, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetRequestId();
        UsageRates::flushCache();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function resetRequestId(): void
    {
        (new \ReflectionProperty(UsageLogger::class, 'requestId'))->setValue(null, null);
    }

    /** @return array<int, object> */
    private function rows(): array
    {
        return Capsule::table('ai_usage_log')->orderBy('id')->get()->all();
    }

    private function logCall(string $model = 'gpt-4o'): void
    {
        UsageLogger::logSuccess(1, 'chat', [
            'provider' => 'openai', 'model' => $model, 'input_tokens' => 1000, 'output_tokens' => 500,
        ], 120);
    }

    public function testACallIsLoggedWithItsCostAndAttribution(): void
    {
        $this->logCall();

        $rows = $this->rows();
        $this->assertSame([], $this->logged);
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(2.0, (float) $rows[0]->cost_usd, 1e-9, '1000 in at $1 per 1k, 500 out at $2 per 1k');
        $this->assertSame([5, 7, 9], [(int) $rows[0]->user_id, (int) $rows[0]->role_id, (int) $rows[0]->app_id]);
        $this->assertSame('2026-11-17 14:30:00', $rows[0]->created_at);
    }

    public function testALongTraceIdStillLeavesAUsageRow(): void
    {
        // What a caller sends as X-DreamFactory-Trace-Id: TraceId accepts up
        // to 64 characters, the column holds 36.
        UsageLogger::setRequestId(str_repeat('t', 64));

        $this->logCall();

        $rows = $this->rows();
        $this->assertCount(1, $rows, 'the spend must be recorded whatever trace id the caller sends');
        $this->assertSame(str_repeat('t', 36), $rows[0]->request_id);
        $this->assertEqualsWithDelta(2.0, (float) $rows[0]->cost_usd, 1e-9);
    }

    public function testAnOverlongModelNameStillLeavesAUsageRow(): void
    {
        // On streams the logged model is the string the caller sent.
        $this->logCall(str_repeat('m', 500));

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(200, strlen($rows[0]->model));
    }

    public function testATimestampTheDatabaseRefusesFallsBackToTheDatabaseClock(): void
    {
        // App clock in UTC, database zone with daylight saving: this local
        // time does not exist there, and a strict database refuses it.
        Carbon::setTestNow(Carbon::create(2027, 3, 14, 2, 30, 0, 'UTC'));

        $this->logCall();

        $rows = $this->rows();
        $this->assertCount(1, $rows, 'the row must be written with the column default');
        $this->assertStringStartsNotWith('2027-03-14 02:', (string) $rows[0]->created_at);
        $this->assertEqualsWithDelta(2.0, (float) $rows[0]->cost_usd, 1e-9);
    }

    public function testACostTooLargeForTheColumnIsHeldToItsMaximum(): void
    {
        Capsule::table('ai_connection_config')->where('service_id', 1)->update(['cost_per_1k_input' => 1_000_000]);
        UsageRates::flushCache();

        $this->logCall();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(9999.999999, (float) $rows[0]->cost_usd, 1e-6);
    }

    public function testAnErrorRowCostsNothing(): void
    {
        UsageLogger::logError(1, 'chat', 'openai', 'gpt-4o', 30000, 'timeout');

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('error', $rows[0]->status);
        $this->assertSame(0.0, (float) $rows[0]->cost_usd);
    }

    public function testNothingIsWrittenWhenUsageLoggingIsOff(): void
    {
        Container::getInstance()->make('config')->set('df-ai.usage_logging.enabled', false);

        $this->logCall();

        $this->assertCount(0, $this->rows());
    }
}
