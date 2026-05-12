<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->decimal('cost_per_1k_input', 10, 6)->nullable()->after('app_id');
            $table->decimal('cost_per_1k_output', 10, 6)->nullable()->after('cost_per_1k_input');
            $table->text('model_rates')->nullable()->after('cost_per_1k_output');
        });
    }

    public function down(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->dropColumn(['cost_per_1k_input', 'cost_per_1k_output', 'model_rates']);
        });
    }
};
