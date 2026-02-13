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
];
