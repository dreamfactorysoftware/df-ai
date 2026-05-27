<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-dimensional AI spend budgets — extends the existing per-service
 * monthly budget on ai_connection_config to also support per-role,
 * per-user, per-app, and global caps.
 *
 * **Why a new table instead of more columns on ai_connection_config:**
 * ai_connection_config.monthly_budget_usd is keyed on the AI Connection.
 * The "marketing team can't go over $500/month" ask is a per-role cap
 * that can't live there. A separate budget table indexed by
 * (dimension_type, dimension_id) supports any combination of
 * service / role / user / app / global budgets, cleanly.
 *
 * Spend is aggregated at check time from ai_usage_log within the
 * budget's period (currently monthly only — daily / hourly are
 * future extensions; the column is there to make those non-breaking).
 *
 * **Threshold tracking:** when a request crosses 50% / 80% / 100% of
 * the budget, we fire a webhook (per-budget URL) once per threshold per
 * period. The `last_alerted_*` timestamps stop us from spamming the
 * webhook on every subsequent request once the threshold has fired.
 *
 * **hard_stop:** when true, requests are REJECTED at 100%. When false,
 * the budget is observability-only — alerts fire but requests proceed.
 * Most regulated buyers want hard_stop=true on per-team caps and
 * hard_stop=false on the global cap (better to flag a runaway than
 * shut down the whole platform).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_budget', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Which dimension this budget applies to. 'global' means
            // "any spend regardless of attribution". The other four are
            // keyed by dimension_id (service_id, role_id, user_id, app_id).
            $table->enum('dimension_type', ['global', 'service', 'role', 'user', 'app']);
            $table->unsignedInteger('dimension_id')->nullable();

            // Period this cap is evaluated over. Future-proofed but only
            // 'monthly' wired up in BudgetEnforcer right now.
            $table->enum('period', ['monthly', 'daily', 'hourly'])->default('monthly');

            $table->decimal('budget_usd', 12, 4);

            // hard_stop=true → reject requests once spend ≥ budget.
            // hard_stop=false → alerts only, requests proceed.
            $table->boolean('hard_stop')->default(false);

            // Alert dispatch.
            $table->string('alert_webhook_url', 1024)->nullable();
            $table->string('alert_webhook_auth_header', 1024)->nullable();

            // Per-threshold last-alerted timestamps. Reset to null when
            // the period rolls over so the next month's first crossing
            // alerts fresh. Reset is handled by BudgetAlerter on access.
            $table->timestamp('last_alerted_50_at')->nullable();
            $table->timestamp('last_alerted_80_at')->nullable();
            $table->timestamp('last_alerted_100_at')->nullable();

            $table->string('label', 256)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Look up budgets by dimension at request time.
            $table->index(['dimension_type', 'dimension_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_budget');
    }
};
