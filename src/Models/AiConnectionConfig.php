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
        'allowed_roles',
        'app_id',
        'cost_per_1k_input',
        'cost_per_1k_output',
        'model_rates',
    ];

    protected $casts = [
        'service_id'         => 'integer',
        'max_tokens'         => 'integer',
        'temperature'        => 'float',
        'timeout'            => 'integer',
        'rate_limit_rpm'     => 'integer',
        'app_id'             => 'integer',
        'cost_per_1k_input'  => 'float',
        'cost_per_1k_output' => 'float',
    ];

    protected $encrypted = ['api_key', 'data_chat_api_keys'];

    protected $protected = ['api_key', 'data_chat_api_keys'];

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
                    ['label' => 'Anthropic (Claude)',           'name' => 'anthropic',        'default_base_url' => 'https://api.anthropic.com'],
                    ['label' => 'OpenAI (GPT)',                 'name' => 'openai',           'default_base_url' => 'https://api.openai.com'],
                    ['label' => 'xAI (Grok)',                   'name' => 'xai',              'default_base_url' => 'https://api.x.ai'],
                    ['label' => 'Ollama (Local)',                'name' => 'ollama',           'default_base_url' => 'http://localhost:11434'],
                    ['label' => 'OpenAI-Compatible (Custom)',    'name' => 'openai_compatible', 'default_base_url' => ''],
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

            case 'allowed_roles':
                $schema['type'] = 'text';
                $schema['label'] = 'Allowed Roles';
                $schema['description'] = 'Deprecated. Use app_id instead.';
                break;

            case 'app_id':
                $schema['type'] = 'integer';
                $schema['label'] = 'Data Access API Key';
                $schema['description'] = 'The DreamFactory API Key the AI uses for data access. The key\'s assigned role determines what data the AI can query.';
                break;

            case 'cost_per_1k_input':
                $schema['type'] = 'number';
                $schema['label'] = 'Default cost per 1k input tokens (USD)';
                $schema['description'] = 'Optional. Used by the Gateway dashboard to compute cost. Falls back to provider defaults when blank. Per-model rates in the rate sheet below override this.';
                break;

            case 'cost_per_1k_output':
                $schema['type'] = 'number';
                $schema['label'] = 'Default cost per 1k output tokens (USD)';
                $schema['description'] = 'Optional. Used by the Gateway dashboard to compute cost. Falls back to provider defaults when blank. Per-model rates in the rate sheet below override this.';
                break;

            case 'model_rates':
                $schema['type'] = 'text';
                $schema['label'] = 'Per-model rate sheet';
                $schema['description'] = 'JSON array of per-model overrides. Each row: {"model":"gpt-4o","input_per_1k":0.0025,"output_per_1k":0.01}. Used to compute cost_usd at log-time. Most accurate when set.';
                break;
        }
    }
}
