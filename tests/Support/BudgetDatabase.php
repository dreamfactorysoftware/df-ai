<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Tests\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * An in-memory SQLite database with the two tables budgets read, so the
 * enforcement rules run against real queries without a DreamFactory stack.
 * Column sets mirror the package migrations.
 */
trait BudgetDatabase
{
    protected function bootBudgetDatabase(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not loaded.');
        }

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('ai_budget', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('dimension_type');
            $table->unsignedInteger('dimension_id')->nullable();
            $table->string('period')->default('monthly');
            $table->decimal('budget_usd', 12, 4);
            $table->boolean('hard_stop')->default(false);
            $table->string('alert_webhook_url', 1024)->nullable();
            $table->text('alert_webhook_auth_header')->nullable();
            $table->timestamp('last_alerted_50_at')->nullable();
            $table->timestamp('last_alerted_80_at')->nullable();
            $table->timestamp('last_alerted_100_at')->nullable();
            $table->string('label', 256)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Capsule::schema()->create('ai_usage_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('service_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('role_id')->nullable();
            $table->unsignedInteger('app_id')->nullable();
            $table->string('resource', 50)->default('chat');
            $table->string('provider', 50)->default('test');
            $table->string('model', 200)->default('test');
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->unsignedInteger('tool_call_count')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->integer('latency_ms')->default(0);
            $table->string('status', 20)->default('success');
            $table->text('error_message')->nullable();
            $table->char('request_id', 36)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /** @param array<string, mixed> $attributes */
    protected function budget(array $attributes): int
    {
        return (int) Capsule::table('ai_budget')->insertGetId($attributes + [
            'dimension_type' => 'global',
            'dimension_id'   => null,
            'period'         => 'monthly',
            'budget_usd'     => 10,
            'hard_stop'      => true,
            'is_active'      => true,
            'label'          => 'test budget',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function spend(float $costUsd, string $createdAt, array $attributes = []): void
    {
        Capsule::table('ai_usage_log')->insert($attributes + [
            'service_id' => 1,
            'user_id'    => 5,
            'role_id'    => 7,
            'app_id'     => 9,
            'cost_usd'   => $costUsd,
            'created_at' => $createdAt,
        ]);
    }
}
