<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiBudget extends Model
{
    protected $table = 'ai_budget';

    protected $fillable = [
        'dimension_type',
        'dimension_id',
        'period',
        'budget_usd',
        'hard_stop',
        'alert_webhook_url',
        'alert_webhook_auth_header',
        'last_alerted_50_at',
        'last_alerted_80_at',
        'last_alerted_100_at',
        'label',
        'is_active',
    ];

    protected $casts = [
        'dimension_id'        => 'integer',
        'budget_usd'          => 'float',
        'hard_stop'           => 'boolean',
        'is_active'           => 'boolean',
        'last_alerted_50_at'  => 'datetime',
        'last_alerted_80_at'  => 'datetime',
        'last_alerted_100_at' => 'datetime',
    ];
}
