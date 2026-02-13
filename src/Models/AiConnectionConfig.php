<?php

namespace DreamFactory\Core\AI\Models;

use DreamFactory\Core\Models\BaseServiceConfigModel;

class AiConnectionConfig extends BaseServiceConfigModel
{
    protected $table = 'ai_connection_config';

    protected $fillable = [
        'service_id',
        'provider',
        'base_url',
        'api_key',
        'default_model',
        'max_tokens',
        'temperature',
        'organization_id',
        'extra_headers',
        'extra_params',
        'timeout',
        'rate_limit_rpm',
        'system_prompt',
        'allowed_models',
    ];

    protected $casts = [
        'service_id'     => 'integer',
        'max_tokens'     => 'integer',
        'temperature'    => 'float',
        'timeout'        => 'integer',
        'rate_limit_rpm' => 'integer',
    ];

    protected $encrypted = ['api_key'];

    protected $protected = ['api_key'];

    /** Known providers with default base URLs. */
    protected static array $providerUrls = [
        'anthropic'        => 'https://api.anthropic.com',
        'openai'           => 'https://api.openai.com',
        'xai'              => 'https://api.x.ai',
        'ollama'           => 'http://localhost:11434',
        'openai_compatible' => '',
    ];

    protected static function boot()
    {
        parent::boot();

        // Auto-fill base_url for known providers when not explicitly set.
        static::saving(function ($model) {
            if (empty($model->base_url) && isset(static::$providerUrls[$model->provider])) {
                $model->base_url = static::$providerUrls[$model->provider];
            }
        });
    }

    protected static function prepareConfigSchemaField(array &$schema)
    {
        parent::prepareConfigSchemaField($schema);

        switch ($schema['name']) {
            case 'provider':
                $schema['type'] = 'picklist';
                $schema['values'] = [
                    ['label' => 'Anthropic (Claude)',      'name' => 'anthropic'],
                    ['label' => 'OpenAI (GPT)',            'name' => 'openai'],
                    ['label' => 'xAI (Grok)',              'name' => 'xai'],
                    ['label' => 'Ollama (Local)',           'name' => 'ollama'],
                    ['label' => 'OpenAI-Compatible (Custom)', 'name' => 'openai_compatible'],
                ];
                $schema['label'] = 'AI Provider';
                $schema['description'] = 'Select the AI/LLM provider to connect to.';
                $schema['required'] = true;
                break;

            case 'api_key':
                $schema['type'] = 'password';
                $schema['label'] = 'API Key';
                $schema['description'] = 'Provider API key. Not required for Ollama (local).';
                break;

            case 'base_url':
                $schema['label'] = 'Base URL';
                $schema['description'] = 'API endpoint URL. Auto-filled for known providers (Anthropic, OpenAI, xAI, Ollama). Required for OpenAI-Compatible custom endpoints.';
                break;

            case 'default_model':
                $schema['label'] = 'Default Model';
                $schema['description'] = 'Default model for requests (e.g., claude-sonnet-4-5-20250929, gpt-4o, grok-2, llama3.2). Can be overridden per request.';
                break;

            case 'max_tokens':
                $schema['label'] = 'Max Tokens';
                $schema['description'] = 'Default maximum tokens for AI responses. Can be overridden per request.';
                break;

            case 'temperature':
                $schema['label'] = 'Temperature';
                $schema['description'] = 'Default temperature (0.0 = deterministic, 1.0 = creative). Can be overridden per request.';
                break;

            case 'organization_id':
                $schema['label'] = 'Organization ID';
                $schema['description'] = 'Optional. OpenAI organization ID, or other provider-specific identifier.';
                break;

            case 'extra_headers':
                $schema['type'] = 'object';
                $schema['label'] = 'Extra Headers';
                $schema['description'] = 'Additional HTTP headers to send with every request (JSON key-value pairs).';
                break;

            case 'extra_params':
                $schema['type'] = 'object';
                $schema['label'] = 'Extra Parameters';
                $schema['description'] = 'Additional provider-specific parameters included in every request (JSON key-value pairs).';
                break;

            case 'timeout':
                $schema['label'] = 'Timeout (seconds)';
                $schema['description'] = 'Request timeout in seconds.';
                break;

            case 'rate_limit_rpm':
                $schema['label'] = 'Rate Limit (RPM)';
                $schema['description'] = 'Maximum requests per minute per user. 0 or empty for unlimited.';
                break;

            case 'system_prompt':
                $schema['type'] = 'text';
                $schema['label'] = 'System Prompt';
                $schema['description'] = 'Default system prompt prepended to all requests made through this connection.';
                break;

            case 'allowed_models':
                $schema['type'] = 'text';
                $schema['label'] = 'Allowed Models';
                $schema['description'] = 'JSON array of allowed model IDs. Empty means all models are allowed. Example: ["claude-sonnet-4-5-20250929","claude-haiku-4-5-20251001"]';
                break;
        }
    }
}
