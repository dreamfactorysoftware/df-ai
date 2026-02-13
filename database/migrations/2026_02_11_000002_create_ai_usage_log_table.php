<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_usage_log')) {
            Schema::create('ai_usage_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('service_id')->unsigned();
                $table->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
                $table->integer('user_id')->unsigned()->nullable();
                $table->integer('role_id')->unsigned()->nullable();
                $table->string('resource', 50);
                $table->string('provider', 50);
                $table->string('model', 200);
                $table->integer('input_tokens')->default(0);
                $table->integer('output_tokens')->default(0);
                $table->integer('latency_ms')->default(0);
                $table->string('status', 20)->default('success');
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['service_id', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_log');
    }
};
