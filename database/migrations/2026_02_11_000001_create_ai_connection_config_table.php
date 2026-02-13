<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_connection_config')) {
            Schema::create('ai_connection_config', function (Blueprint $table) {
                $table->integer('service_id')->unsigned()->primary();
                $table->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
                $table->string('provider', 50);
                $table->string('base_url', 500)->nullable();
                $table->text('api_key')->nullable();
                $table->string('default_model', 200)->nullable()->default('');
                $table->integer('max_tokens')->default(1024);
                $table->decimal('temperature', 3, 2)->default(0.70);
                $table->string('organization_id', 200)->nullable();
                $table->text('extra_headers')->nullable();
                $table->text('extra_params')->nullable();
                $table->integer('timeout')->default(30);
                $table->integer('rate_limit_rpm')->nullable();
                $table->text('system_prompt')->nullable();
                $table->text('allowed_models')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_connection_config');
    }
};
