<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider fallback chains — production reliability gate.
 *
 * `fallback_service_ids` is an ordered JSON array of AI Connection
 * service ids. When the primary provider fails with a retryable error
 * (429 / 5xx / connection timeout), the resource layer transparently
 * tries the next fallback. Non-retryable errors (4xx other than 429)
 * propagate immediately — there's no point retrying a malformed request.
 *
 * Each attempt produces its own ai_usage_log row so cost + latency
 * stay attributable per-provider (joined via the shared request_id).
 *
 * Why store as a JSON array: the order matters (try cheaper / faster
 * fallback first), and the chain length varies per customer. JSON keeps
 * the schema simple without a join table.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->json('fallback_service_ids')->nullable()->after('audit_file_sink_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->dropColumn('fallback_service_ids');
        });
    }
};
