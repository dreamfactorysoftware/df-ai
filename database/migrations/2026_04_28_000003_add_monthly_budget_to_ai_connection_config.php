<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->decimal('monthly_budget_usd', 12, 2)->nullable()->after('model_rates');
        });
    }

    public function down(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->dropColumn('monthly_budget_usd');
        });
    }
};
