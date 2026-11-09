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
    private const SERIES_KEYS = [
        'bucket',
        'cost_usd',
        'date',
        'input_tokens',
        'output_tokens',
        'requests',
    ];

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

    public function testEverySeriesRowIsAPlainAttributeArray(): void
    {
        $this->seed();

        $rows = $this->seriesByUser();

        $this->assertCount(6, $rows, '3 top-N rows + 3 Other rows');
        foreach ($rows as $i => $row) {
            $this->assertIsArray($row);
            $keys = array_keys($row);
            sort($keys);
            $this->assertSame(
                self::SERIES_KEYS,
                $keys,
                "series row #$i must carry exactly the selected columns plus bucket"
            );
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                (string) $row['date'],
                "series row #$i must have a calendar date"
            );
        }
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

    public function testSeriesCostReconcilesWithTheWindowTotal(): void
    {
        $this->seed();

        // The chart stacks these rows; the stack must add up to the period
        // total or the dashboard under-reports spend.
        $sum = 0.0;
        foreach ($this->seriesByUser() as $row) {
            $sum += (float) ($row['cost_usd'] ?? 0);
        }

        $this->assertEqualsWithDelta(12.50, $sum, 1e-9);
    }

    public function testSeriesSurvivesTheJsonResponse(): void
    {
        $this->seed();

        // What the dashboard actually receives.
        $decoded = json_decode(json_encode($this->seriesByUser(), JSON_THROW_ON_ERROR), true);

        $dates = array_map(fn($r) => $r['date'] ?? null, $decoded);
        $this->assertNotContains(null, $dates, 'every row needs a date after JSON encoding');
        $buckets = array_values(array_unique(array_map(fn($r) => (string) $r['bucket'], $decoded)));
        sort($buckets);
        $this->assertSame(['1', '2', UsageAggregator::OTHER_BUCKET], $buckets);
    }

    public function testNoOtherBucketWhenEverythingFitsInTopN(): void
    {
        AiUsageLog::query()->insert([
            'service_id' => 7, 'user_id' => 1, 'provider' => 'anthropic',
            'input_tokens' => 10, 'output_tokens' => 5, 'cost_usd' => 0.1,
            'status' => 'success', 'created_at' => '2026-11-03 10:00:00',
        ]);

        $rows = $this->seriesByUser();

        $this->assertCount(1, $rows);
        $this->assertSame('2026-11-03', $rows[0]['date']);
        $this->assertSame('1', (string) $rows[0]['bucket']);
    }

    public function testAttachCostPerThousandAcceptsEloquentModels(): void
    {
        $this->seed();

        // A caller that skips ->toArray() hands over models, not arrays.
        $models = AiUsageLog::query()
            ->selectRaw(
                'model, SUM(input_tokens) as input_tokens, '
                . 'SUM(output_tokens) as output_tokens, SUM(cost_usd) as cost_usd'
            )
            ->groupBy('model')
            ->get()
            ->all();
        $this->assertInstanceOf(Model::class, $models[0]);

        $out = UsageAggregator::attachCostPerThousand($models);

        // 2500 input + 1250 output tokens for $12.50 = $3.333333 per 1k.
        $this->assertSame('claude-sonnet-4-6', $out[0]['model'] ?? null);
        $this->assertEqualsWithDelta(3.333333, $out[0]['cost_per_1k_tokens'], 1e-6);
    }
}
