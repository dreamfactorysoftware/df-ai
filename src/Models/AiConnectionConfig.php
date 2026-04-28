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

            // Reject malformed model_rates rather than letting it silently fall
            // through to per-service flat rates at log time (which would give
            // misleading cost numbers without telling the admin why).
            if (!empty($model->model_rates)) {
                $decoded = json_decode((string) $model->model_rates, true);
                if (!is_array($decoded)) {
                    throw new \InvalidArgumentException(
                        'model_rates must be valid JSON.'
                    );
                }
                foreach ($decoded as $i => $row) {
                    if (!is_array($row) || empty($row['model'])) {
                        throw new \InvalidArgumentException(
                            "model_rates[$i] requires a non-empty 'model' field."
                        );
                    }
                    foreach (['input_per_1k', 'output_per_1k'] as $rateKey) {
                        if (isset($row[$rateKey]) && !is_numeric($row[$rateKey])) {
                            throw new \InvalidArgumentException(
                                "model_rates[$i].$rateKey must be numeric."
                            );
                        }
                    }
                }
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
                $schema['description'] = 'Which LLM provider this AI Connection talks to. Each provider needs different credentials and endpoints — picking one auto-fills the Base URL.';
                $schema['required'] = true;
                break;

            case 'api_key':
                $schema['type'] = 'password';
                $schema['label'] = 'API Key';
                $schema['description'] = 'Provider API key (encrypted at rest). Anthropic, OpenAI, and xAI all require one. Ollama does not — it runs locally without auth. For OpenAI-Compatible endpoints, paste whatever bearer the server expects.';
                break;

            case 'base_url':
                $schema['label'] = 'Base URL';
                $schema['description'] = 'Where this connection sends requests. Auto-filled when you pick a provider above. Override only for self-hosted endpoints, regional gateways, or proxies. OpenAI-Compatible providers must set this manually.';
                break;

            case 'default_model':
                $schema['label'] = 'Default Model';
                $schema['description'] = 'Model used when a caller does not specify one. Examples: claude-sonnet-4-5, gpt-4o, gpt-4o-mini, grok-2, llama3.2. Each request can override this. If "Allowed Models" below is set, the default must be on that list.';
                break;

            case 'max_tokens':
                $schema['label'] = 'Max output tokens';
                $schema['description'] = 'Cap on the response length, in tokens. Does not limit the input. Each request can override. Set lower to control runaway responses; the API errors out if the model wants more.';
                break;

            case 'temperature':
                $schema['label'] = 'Temperature';
                $schema['description'] = 'How random/creative responses are. 0 = deterministic, ~0.7 = balanced, 1.0 = creative. Anthropic accepts up to 1.0; OpenAI accepts up to 2.0. Each request can override.';
                break;

            case 'organization_id':
                $schema['label'] = 'Organization ID';
                $schema['description'] = 'Optional. OpenAI customers on a team plan use this to bill the right org. Most providers ignore it. Leave blank if you are not sure.';
                break;

            case 'extra_headers':
                $schema['type'] = 'object';
                $schema['label'] = 'Extra Headers';
                $schema['description'] = 'JSON object of extra HTTP headers attached to every request. Common uses: pinning Anthropic API version ({"anthropic-version":"2023-06-01"}), passing Cloudflare access tokens, or routing through a corporate proxy.';
                break;

            case 'extra_params':
                $schema['type'] = 'object';
                $schema['label'] = 'Extra Parameters';
                $schema['description'] = 'JSON object merged into every request body. Use for provider-specific knobs DF does not surface explicitly (e.g. {"top_p":0.9} or {"reasoning":{"effort":"low"}}).';
                break;

            case 'timeout':
                $schema['label'] = 'Timeout (seconds)';
                $schema['description'] = 'Abort the request if the provider has not responded in this many seconds. Bump higher for long completions or local Ollama models that warm up slowly.';
                break;

            case 'rate_limit_rpm':
                $schema['label'] = 'Rate limit (requests per minute)';
                $schema['description'] = 'Per-user cap on requests per minute through this connection. Stops a single user from blowing through your provider quota. 0 or blank = unlimited.';
                break;

            case 'system_prompt':
                $schema['type'] = 'text';
                $schema['label'] = 'Default system prompt';
                $schema['description'] = 'Instructions injected at the top of every conversation as the AI\'s persona/guardrails. Each request can append more, but cannot remove this. Use it to enforce tone, limit topics, or remind the model who it is talking to.';
                break;

            case 'allowed_models':
                $schema['type'] = 'text';
                $schema['label'] = 'Allowed Models';
                $schema['description'] = 'Optional JSON array gating which model names callers can request. Empty = all models the provider exposes. Useful for cost control (block expensive models) or compliance (only approved models). Example: ["claude-sonnet-4-5","claude-haiku-4-5"]';
                break;

            case 'allowed_roles':
                $schema['type'] = 'text';
                $schema['label'] = 'Allowed Roles';
                $schema['description'] = 'Optional JSON array of DreamFactory role IDs that may call this connection. Empty = any authenticated user with access. This gates WHO can use the AI; the "Data Access API Key" below gates WHAT data the AI can read.';
                break;

            case 'app_id':
                $schema['type'] = 'integer';
                $schema['label'] = 'Data Access API Key';
                $schema['description'] = 'When the AI runs tools (read database, list files, etc.) it acts as this DreamFactory API key. The key\'s role determines which tables/services the AI can read. Create a least-privilege key for the AI — do not reuse a high-privilege admin key.';
                break;

            case 'cost_per_1k_input':
                $schema['type'] = 'number';
                $schema['label'] = 'Default cost per 1k input tokens (USD)';
                $schema['description'] = 'Optional fallback rate used by the Gateway dashboard to compute spend. Per-model rates in the rate sheet below take priority. Leave blank to fall back to DreamFactory\'s built-in provider defaults.';
                break;

            case 'cost_per_1k_output':
                $schema['type'] = 'number';
                $schema['label'] = 'Default cost per 1k output tokens (USD)';
                $schema['description'] = 'Optional fallback rate. Same lookup chain as input — per-model rates win, then this, then provider defaults. Output tokens usually cost 4-5× input tokens.';
                break;

            case 'model_rates':
                $schema['type'] = 'text';
                $schema['label'] = 'Per-model rate sheet';
                $schema['description'] = 'Optional JSON array of per-model token prices. The most accurate way to track cost when one connection serves multiple model tiers (e.g. gpt-4o + gpt-4o-mini). Cost is computed and stored at log-time, so price changes do not rewrite history. Example: [{"model":"gpt-4o","input_per_1k":0.0025,"output_per_1k":0.01},{"model":"gpt-4o-mini","input_per_1k":0.00015,"output_per_1k":0.0006}]';
                break;
        }
    }
}
