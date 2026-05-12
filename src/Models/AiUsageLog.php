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
        'app_id',
        'resource',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'tool_call_count',
        'cost_usd',
        'latency_ms',
        'status',
        'error_message',
        'request_id',
    ];

    protected $casts = [
        'service_id'      => 'integer',
        'user_id'         => 'integer',
        'role_id'         => 'integer',
        'app_id'          => 'integer',
        'input_tokens'    => 'integer',
        'output_tokens'   => 'integer',
        'tool_call_count' => 'integer',
        'cost_usd'        => 'float',
        'latency_ms'      => 'integer',
        'created_at'      => 'datetime',
    ];
}
