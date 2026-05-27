<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit log of prompts + responses for AI Gateway requests.
 *
 * Separate from {@see AiUsageLog} so the analytics tables stay small
 * and prompt retention can diverge (e.g. delete prompts at 30 days for
 * cost, keep usage rows 1 year for analytics, OR vice-versa for HIPAA
 * 7-year retention on prompts but rolled-up usage).
 *
 * Joins to AiUsageLog via `request_id` (UUID set by UsageLogger). One
 * usage row → 0-or-1 prompt row (logging is opt-in per AI Connection).
 *
 * `request_payload` and `response_payload` are NOT in `$encrypted`
 * because the redactor already scrubs PII before storage. Adding DF's
 * standard at-rest encryption can be a deployment-time choice (column
 * encryption via the database, not application-layer) — application-
 * layer encryption blocks SIEM exports from being readable on the wire,
 * which defeats the SOC integration purpose. Customers who need
 * application-layer can flip the per-column $encrypted list themselves.
 */
class AiPromptLog extends Model
{
    protected $table = 'ai_prompt_log';

    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'service_id',
        'user_id',
        'role_id',
        'app_id',
        'provider',
        'model',
        'resource',
        'request_payload',
        'response_payload',
        'redaction_count',
        'original_size_bytes',
        'status',
    ];

    protected $casts = [
        'service_id'          => 'integer',
        'user_id'             => 'integer',
        'role_id'             => 'integer',
        'app_id'              => 'integer',
        'redaction_count'     => 'integer',
        'original_size_bytes' => 'integer',
        'created_at'          => 'datetime',
    ];
}
