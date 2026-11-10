<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Unit\Utility;

use DreamFactory\Core\AI\Models\AiUsageLog;
use DreamFactory\Core\AI\Utility\UsageAggregator;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Runs UsageAggregator's series queries against a real ai_usage_log table
 * (in-memory SQLite) with real Eloquent models.
 *
 * Regression cover for the "Other" bucket: its rows used to be built with
 * `(array) $model`, which yields the model's internal property bag instead
 * of its attributes. Every Other row reached the dashboard with no `date`
 * and no `cost_usd`.
 */
class UsageAggregatorSeriesTest extends TestCase
{
    private ?ConnectionResolverInterface $previousResolver = null;

    /** @var mixed */
    private $previousFacadeApp = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for the series tests.');
        }

        $this->previousResolver = Model::getConnectionResolver();
        $this->previousFacadeApp = Facade::getFacadeApplication();

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $capsule->bootEloquent();

        // UsageAggregator calls the global \DB alias for raw expressions.
        // A full app registers it; here we point the facade at the capsule.
        $app = new Container();
        $app->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        if (!class_exists('DB')) {
            class_alias(DB::class, 'DB');
        }

        $capsule->getConnection()->statement(
            'CREATE TABLE ai_usage_log ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'service_id INTEGER, user_id INTEGER, role_id INTEGER, app_id INTEGER, '
            . 'resource TEXT, provider TEXT, model TEXT, '
            . 'input_tokens INTEGER, output_tokens INTEGER, tool_call_count INTEGER, '
            . 'cost_usd REAL, latency_ms INTEGER, status TEXT, error_message TEXT, '
            . 'request_id TEXT, created_at TEXT)'
        );
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApp);
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }

        parent::tearDown();
    }

    /**
     * Three users plus unattributed rows over three days. With topN = 2 the
     * third user and the null-user rows fall into the Other bucket.
     */
    private function seed(): void
    {
        $rows = [
            // [user_id, created_at, input, output, cost]
            [1, '2026-11-03 10:00:00', 1000, 500, 5.00],
            [1, '2026-11-04 10:00:00', 600, 300, 3.00],
            [2, '2026-11-03 11:00:00', 400, 200, 2.00],
            [3, '2026-11-04 12:00:00', 300, 150, 1.50],
            [null, '2026-11-03 13:00:00', 50, 25, 0.25],
            [null, '2026-11-05 09:00:00', 150, 75, 0.75],
        ];
        foreach ($rows as [$userId, $createdAt, $in, $out, $cost]) {
            AiUsageLog::query()->insert([
                'service_id'    => 7,
                'user_id'       => $userId,
                'app_id'        => 1,
                'provider'      => 'anthropic',
                'model'         => 'claude-sonnet-4-6',
                'resource'      => 'chat',
                'input_tokens'  => $in,
                'output_tokens' => $out,
                'cost_usd'      => $cost,
                'latency_ms'    => 100,
                'status'        => 'success',
                'created_at'    => $createdAt,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function seriesByUser(): array
    {
        return UsageAggregator::seriesByDimension(
            Carbon::parse('2026-11-01 00:00:00'),
            'sqlite',
            [],
            'user_id',
            2,
        );
    }

    public function testOtherBucketRowsCarryDateAndTotals(): void
    {
        $this->seed();

        $other = [];
        foreach ($this->seriesByUser() as $row) {
            if (($row['bucket'] ?? null) === UsageAggregator::OTHER_BUCKET) {
                $other[$row['date'] ?? '(no date)'] = $row;
            }
        }

        $this->assertSame(
            ['2026-11-03', '2026-11-04', '2026-11-05'],
            array_keys($other)
        );
        $this->assertEqualsWithDelta(0.25, $other['2026-11-03']['cost_usd'], 1e-9);
        $this->assertEqualsWithDelta(1.50, $other['2026-11-04']['cost_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.75, $other['2026-11-05']['cost_usd'], 1e-9);
        $this->assertSame(1, (int) $other['2026-11-04']['requests']);
        $this->assertSame(300, (int) $other['2026-11-04']['input_tokens']);
        $this->assertSame(150, (int) $other['2026-11-04']['output_tokens']);
    }

}
