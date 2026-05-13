<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt + response audit log for the AI Gateway.
 *
 * Separate from `ai_usage_log` for two reasons:
 *   1. Retention can diverge — prompts may be stored 7 years (HIPAA),
 *      while usage rows pruned at 90 days for performance.
 *   2. Prompts are LARGE (TEXT/MEDIUMTEXT) and most analytical queries
 *      against ai_usage_log don't need them — keeping prompts in their
 *      own table keeps the analytics tables small + fast.
 *
 * Each row joins back to `ai_usage_log` via `request_id` (UUID set by
 * UsageLogger). One ai_usage_log row → 0 or 1 ai_prompt_log row (logging
 * is opt-in per AI Connection).
 *
 * `request_payload` and `response_payload` carry the raw or redacted
 * messages. They go through DF's standard Encryptable trait at the
 * model layer — at-rest encryption is automatic.
 *
 * `redaction_count` records how many patterns matched during ingestion.
 * Lets the dashboard show "12% of prompts hit the SSN redactor" without
 * having to re-scan the stored content.
 *
 * `original_size_bytes` tracks pre-redaction size — useful for tuning
 * tool-result truncation thresholds without exposing the original.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_prompt_log', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Correlation key back to ai_usage_log + per-request join.
            $table->uuid('request_id')->index();

            // Attribution — denormalized from ai_usage_log so SIEM exports
            // can ship a single self-describing row without a JOIN.
            $table->unsignedInteger('service_id')->index();
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->unsignedInteger('role_id')->nullable()->index();
            $table->unsignedInteger('app_id')->nullable()->index();
            $table->string('provider', 64)->index();
            $table->string('model', 128)->index();
            $table->string('resource', 64)->index();

            // Encrypted at rest via AiPromptLog model's $encrypted list.
            // Storing redacted (or raw, when redaction is disabled) content.
            $table->mediumText('request_payload')->nullable();
            $table->mediumText('response_payload')->nullable();

            // Redaction telemetry — non-secret, safe to query/index.
            $table->unsignedSmallInteger('redaction_count')->default(0);
            $table->unsignedInteger('original_size_bytes')->nullable();

            // Status mirrors ai_usage_log so error rows can be filtered
            // out of compliance exports without joining.
            $table->string('status', 16)->default('success')->index();

            $table->timestamp('created_at')->useCurrent()->index();

            // Composite index: the most common query is "give me all
            // prompts for this service in the last N days for export"
            $table->index(['service_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_log');
    }
};
