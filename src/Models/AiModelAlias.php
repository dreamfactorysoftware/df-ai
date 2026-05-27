<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Logical model name → (AI Connection service id + physical model)
 * mapping for the OpenAI-compatible gateway endpoint.
 *
 * @see ai_model_alias migration for the schema rationale.
 */
class AiModelAlias extends Model
{
    protected $table = 'ai_model_alias';

    protected $fillable = [
        'name',
        'service_id',
        'physical_model',
        'label',
        'description',
        'is_active',
    ];

    protected $casts = [
        'service_id' => 'integer',
        'is_active'  => 'boolean',
    ];
}
