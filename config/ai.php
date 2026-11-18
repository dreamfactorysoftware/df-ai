<?php

return [
    // Default provider base URLs (auto-filled when not explicitly configured)
    'provider_urls' => [
        'anthropic'        => 'https://api.anthropic.com',
        'openai'           => 'https://api.openai.com',
        'xai'              => 'https://api.x.ai',
        'ollama'           => 'http://localhost:11434',
        'openai_compatible' => '', // Must be set by user
    ],

    // Anthropic API version header
    'anthropic_version' => '2023-06-01',

    // Default request settings (can be overridden per-service in config)
    'defaults' => [
        'max_tokens'   => 1024,
        'temperature'  => 0.7,
        'timeout'      => 30,
    ],

    // Usage logging
    'usage_logging' => [
        'enabled' => true,
        'retention_days' => 90,
    ],

    // AI budgets and model aliases. Each switch below is one of the open
    // questions in the budget spec. Every default is the restrictive choice.
    'budgets' => [
        // Open question 4 (target release). The management API and, through
        // it, the admin screens. Off, the eight /_internal/ai/budgets and
        // /_internal/ai/aliases routes are not registered and answer 404, as
        // on a server without the feature, so this code can merge and ship
        // before the release that announces it. Enforcement is not behind
        // this switch: caps on every AI path apply either way.
        'management_enabled' => env('DF_AI_BUDGET_MANAGEMENT_ENABLED', false),

        // Open question 1 (tier). Lowest license that may manage budgets and
        // aliases: 'GOLD', 'SILVER', or null for no license check. This gates
        // the management API only. Rules that already exist are always
        // enforced, on every tier.
        'required_license' => env('DF_AI_BUDGET_REQUIRED_LICENSE', 'GOLD'),

        // Open question 2 (non-sysadmin access). Who may manage budgets and
        // aliases: 'sysadmin' is full administrators only. 'admin' also lets
        // in restricted administrators (an admin limited to some tabs).
        'manage_access' => env('DF_AI_BUDGET_MANAGE_ACCESS', 'sysadmin'),

        // Open question 3 (webhook default). Alert webhooks are off until an
        // operator turns them on. When on, a URL must be http or https with no
        // embedded credentials, and its host must resolve to public addresses
        // only. Name an internal receiver in allowed_hosts (comma separated)
        // to permit it. With allowed_hosts set, only those hosts are accepted.
        'webhooks' => [
            'enabled'       => env('DF_AI_BUDGET_WEBHOOKS_ENABLED', false),
            'allowed_hosts' => env('DF_AI_BUDGET_WEBHOOK_ALLOWED_HOSTS', ''),
        ],
    ],

    // Data Chat settings
    'data_chat' => [
        'max_iterations'      => 10,
        'max_result_chars'    => 50000,
        'query_limit_max'     => 1000,
        'default_max_tokens'  => 4096,
        'default_temperature' => 0.2,
        'mcp_host'            => env('DF_MCP_HOST', 'http://127.0.0.1:8006'),
    ],
];
