<?php

namespace DreamFactory\Core\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $table = 'ai_usage_log';

    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'user_id',
        'role_id',
        'resource',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'status',
        'error_message',
    ];

    protected $casts = [
        'service_id'    => 'integer',
        'user_id'       => 'integer',
        'role_id'       => 'integer',
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'latency_ms'    => 'integer',
        'created_at'    => 'datetime',
    ];
}
