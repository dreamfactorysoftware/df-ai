<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_log', function (Blueprint $table) {
            $table->integer('app_id')->unsigned()->nullable()->after('role_id');
            $table->foreign('app_id')->references('id')->on('app')->onDelete('set null');
            $table->index(['app_id', 'created_at']);

            $table->integer('tool_call_count')->unsigned()->default(0)->after('output_tokens');
            $table->decimal('cost_usd', 10, 6)->default(0)->after('tool_call_count');
            $table->char('request_id', 36)->nullable()->after('error_message');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_log', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn('request_id');
            $table->dropColumn('cost_usd');
            $table->dropColumn('tool_call_count');
            $table->dropIndex(['app_id', 'created_at']);
            $table->dropForeign(['app_id']);
            $table->dropColumn('app_id');
        });
    }
};
