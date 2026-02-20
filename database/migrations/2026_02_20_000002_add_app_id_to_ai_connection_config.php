<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->integer('app_id')->unsigned()->nullable()->after('allowed_roles');
            $table->foreign('app_id')->references('id')->on('app')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('ai_connection_config', function (Blueprint $table) {
            $table->dropForeign(['app_id']);
            $table->dropColumn('app_id');
        });
    }
};
