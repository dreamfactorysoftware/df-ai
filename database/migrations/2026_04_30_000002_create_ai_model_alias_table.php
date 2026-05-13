<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model alias registry for the OpenAI-compatible gateway endpoint.
 *
 * Customer apps speak OpenAI to DF (`OPENAI_BASE_URL=https://df.example
 * /api/v2/_ai`) and pass logical model names like "claude-sonnet" or
 * "gpt-4o-mini". This table maps those logical names to (AI Connection
 * service id + physical model name) so an admin can:
 *
 *   - rename "claude-sonnet" → "anthropic/claude-sonnet-4-5" without
 *     touching the customer's app code
 *   - swap a paid Anthropic call for a local Qwen instance overnight
 *     by changing the alias's service_id
 *   - expose only the models compliance has approved (the alias list
 *     IS the list of models customers can ask for)
 *
 * The alias `name` is what clients send in `model: "..."` per the
 * OpenAI spec. It must be unique across the install (one alias points
 * at exactly one provider+model) — uniqueness keyed on `name`.
 *
 * `is_active` lets admins disable an alias without deleting it
 * (keeps the historical bind intact for audit purposes when an alias
 * appears in past ai_usage_log rows).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_model_alias', function (Blueprint $table) {
            $table->bigIncrements('id');

            // The logical name clients send as "model": "..."
            $table->string('name', 128)->unique();

            // Where this alias resolves to.
            $table->unsignedInteger('service_id')->index();
            $table->string('physical_model', 128);

            // Optional admin label / description for the dashboard UI.
            $table->string('label', 256)->nullable();
            $table->text('description')->nullable();

            // Soft-disable without deletion (preserves audit log links).
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_alias');
    }
};
