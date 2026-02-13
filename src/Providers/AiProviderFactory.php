<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Providers;

use DreamFactory\Core\AI\Models\AiConnectionConfig;
use InvalidArgumentException;

/**
 * Factory for creating AI provider instances from DreamFactory service config.
 */
class AiProviderFactory
{
    /**
     * Create a provider from a raw config array (as stored in ai_connection_config).
     */
    public static function make(array $config): AiProviderInterface
    {
        $provider = $config['provider'] ?? '';

        if (empty($provider)) {
            throw new InvalidArgumentException('AI provider type is required.');
        }

        $baseUrl      = $config['base_url'] ?? self::defaultUrl($provider);
        $apiKey       = $config['api_key'] ?? null;
        $model        = $config['default_model'] ?? '';
        $maxTokens    = (int) ($config['max_tokens'] ?? 1024);
        $temperature  = (float) ($config['temperature'] ?? 0.7);
        $timeout      = (int) ($config['timeout'] ?? 30);
        $systemPrompt = $config['system_prompt'] ?? null;
        $orgId        = $config['organization_id'] ?? null;
        $extraHeaders = self::decodeJson($config['extra_headers'] ?? null);
        $extraParams  = self::decodeJson($config['extra_params'] ?? null);

        $args = [
            $baseUrl, $apiKey, $model, $maxTokens, $temperature,
            $timeout, $systemPrompt, $extraHeaders, $extraParams, $orgId,
        ];

        return match ($provider) {
            'anthropic'        => new AnthropicProvider(...$args),
            'openai'           => new OpenAIProvider(...$args),
            'xai'              => new XaiProvider(...$args),
            'ollama'           => new OllamaProvider(...$args),
            'openai_compatible' => new OpenAICompatibleProvider(...$args),
            default            => throw new InvalidArgumentException("Unknown AI provider: {$provider}"),
        };
    }

    /**
     * Create a provider from a named DreamFactory service.
     *
     * This is the primary way other packages (Guardian, Chat, etc.) get a provider.
     */
    public static function fromService(string $serviceName): AiProviderInterface
    {
        $service = \ServiceManager::getService($serviceName);

        if (!$service) {
            throw new InvalidArgumentException("AI service not found: {$serviceName}");
        }

        $config = $service->getConfig();

        // getConfig() may return array or need to be loaded from the model.
        if (empty($config) || empty($config['provider'] ?? null)) {
            $configModel = AiConnectionConfig::whereServiceId($service->getServiceId())->first();
            if ($configModel) {
                $config = $configModel->toArray();
            }
        }

        return self::make($config);
    }

    /**
     * Create a provider from a service ID.
     */
    public static function fromServiceId(int $serviceId): AiProviderInterface
    {
        $configModel = AiConnectionConfig::whereServiceId($serviceId)->first();

        if (!$configModel) {
            throw new InvalidArgumentException("AI connection config not found for service ID: {$serviceId}");
        }

        return self::make($configModel->toArray());
    }

    private static function defaultUrl(string $provider): string
    {
        return match ($provider) {
            'anthropic' => 'https://api.anthropic.com',
            'openai'    => 'https://api.openai.com',
            'xai'       => 'https://api.x.ai',
            'ollama'    => 'http://localhost:11434',
            default     => '',
        };
    }

    private static function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
