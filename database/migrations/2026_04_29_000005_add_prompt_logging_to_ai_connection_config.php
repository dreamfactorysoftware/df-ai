<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-AI-Connection knobs for prompt logging + SIEM forwarding.
 *
 * All default OFF — regulated customers explicitly opt in (some
 * use cases legally cannot store prompts at all, so the default
 * is to NOT collect them).
 *
 *   prompt_logging_enabled        — flip it on, prompts get logged
 *   prompt_redaction_rules        — JSON array of {pattern, replacement, label}
 *                                   in addition to the built-in PII patterns
 *   prompt_log_retention_days     — overrides global default; 0 = forever
 *   prompt_redact_pii             — apply built-in SSN/CC/email/phone/MRN
 *   audit_webhook_url             — POST destination for ECS audit events
 *   audit_webhook_format          — 'ecs' (default) | 'splunk_hec' | 'datadog'
 *   audit_webhook_auth_header     — opaque value passed as Authorization
 *                                   (e.g. "Splunk <token>" for HEC)
 *   audit_file_sink_enabled       — write NDJSON to a configured file path
 *                                   for Logstash file-input
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->boolean('prompt_logging_enabled')->default(false)->after('monthly_budget_usd');
            $table->boolean('prompt_redact_pii')->default(true)->after('prompt_logging_enabled');
            $table->json('prompt_redaction_rules')->nullable()->after('prompt_redact_pii');
            $table->unsignedInteger('prompt_log_retention_days')->nullable()->after('prompt_redaction_rules');

            // SIEM forwarding — webhook push is per-connection so different
            // AI Connections can route to different destinations (e.g.
            // production prompts → Splunk, dev prompts → null).
            $table->string('audit_webhook_url', 1024)->nullable()->after('prompt_log_retention_days');
            $table->string('audit_webhook_format', 32)->nullable()->after('audit_webhook_url');
            $table->string('audit_webhook_auth_header', 1024)->nullable()->after('audit_webhook_format');
            $table->boolean('audit_file_sink_enabled')->default(false)->after('audit_webhook_auth_header');
        });
    }

    public function down(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->dropColumn([
                'prompt_logging_enabled',
                'prompt_redact_pii',
                'prompt_redaction_rules',
                'prompt_log_retention_days',
                'audit_webhook_url',
                'audit_webhook_format',
                'audit_webhook_auth_header',
                'audit_file_sink_enabled',
            ]);
        });
    }
};
