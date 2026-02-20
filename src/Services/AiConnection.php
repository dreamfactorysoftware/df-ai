<?php

namespace DreamFactory\Core\AI\Services;

use DreamFactory\Core\AI\Providers\AiProviderFactory;
use DreamFactory\Core\AI\Providers\AiProviderInterface;
use DreamFactory\Core\AI\Resources\ChatResource;
use DreamFactory\Core\AI\Resources\CompletionResource;
use DreamFactory\Core\AI\Resources\DataChatResource;
use DreamFactory\Core\AI\Resources\EmbeddingsResource;
use DreamFactory\Core\AI\Resources\HealthResource;
use DreamFactory\Core\AI\Resources\ModelsResource;
use DreamFactory\Core\AI\Resources\UsageResource;
use DreamFactory\Core\Services\BaseRestService;

class AiConnection extends BaseRestService
{
    protected static $resources = [
        CompletionResource::RESOURCE_NAME => [
            'name'       => CompletionResource::RESOURCE_NAME,
            'class_name' => CompletionResource::class,
            'label'      => 'Completion',
        ],
        ChatResource::RESOURCE_NAME => [
            'name'       => ChatResource::RESOURCE_NAME,
            'class_name' => ChatResource::class,
            'label'      => 'Chat',
        ],
        ModelsResource::RESOURCE_NAME => [
            'name'       => ModelsResource::RESOURCE_NAME,
            'class_name' => ModelsResource::class,
            'label'      => 'Models',
        ],
        EmbeddingsResource::RESOURCE_NAME => [
            'name'       => EmbeddingsResource::RESOURCE_NAME,
            'class_name' => EmbeddingsResource::class,
            'label'      => 'Embeddings',
        ],
        HealthResource::RESOURCE_NAME => [
            'name'       => HealthResource::RESOURCE_NAME,
            'class_name' => HealthResource::class,
            'label'      => 'Health',
        ],
        UsageResource::RESOURCE_NAME => [
            'name'       => UsageResource::RESOURCE_NAME,
            'class_name' => UsageResource::class,
            'label'      => 'Usage',
        ],
        DataChatResource::RESOURCE_NAME => [
            'name'       => DataChatResource::RESOURCE_NAME,
            'class_name' => DataChatResource::class,
            'label'      => 'Data Chat',
        ],
    ];

    /**
     * Get a configured provider instance for this service.
     */
    public function getProvider(): AiProviderInterface
    {
        return AiProviderFactory::make($this->config);
    }

    /**
     * Get the config value with a key.
     */
    public function getConfig($key = null, $default = null)
    {
        if ($key === null) {
            return $this->config;
        }

        return data_get($this->config, $key, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessList()
    {
        $resources = [];
        foreach (static::$resources as $name => $info) {
            $resources[] = $name . '/';
            $resources[] = $name . '/*';
        }
        return $resources;
    }
}
