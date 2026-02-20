<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->text('allowed_roles')->nullable()->after('allowed_models');
        });
    }

    public function down(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->dropColumn('allowed_roles');
        });
    }
};
