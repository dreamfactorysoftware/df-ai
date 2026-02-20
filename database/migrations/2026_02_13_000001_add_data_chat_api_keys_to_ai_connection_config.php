<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_connection_config') && !Schema::hasColumn('ai_connection_config', 'data_chat_api_keys')) {
            Schema::table('ai_connection_config', function (Blueprint $table) {
                $table->text('data_chat_api_keys')->nullable()->after('allowed_models');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_connection_config', 'data_chat_api_keys')) {
            Schema::table('ai_connection_config', function (Blueprint $table) {
                $table->dropColumn('data_chat_api_keys');
            });
        }
    }
};
