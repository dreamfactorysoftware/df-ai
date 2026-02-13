<?php

namespace DreamFactory\Core\AI\Resources;

use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\ResourcesWrapper;

class ModelsResource extends BaseRestResource
{
    const RESOURCE_NAME = 'models';

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);

        return [
            '/models' => [
                'get' => [
                    'summary'     => 'List available AI models.',
                    'description' => 'Returns the models available from this AI provider. Filtered by allowed_models if configured.',
                    'operationId' => 'get' . $capitalized . 'Models',
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/ModelsResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocResponses()
    {
        return [
            'ModelsResponse' => [
                'description' => 'Models list response',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ModelsResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocSchemas()
    {
        return [
            'AiModel' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'Model identifier (e.g. claude-sonnet-4-5-20250929, gpt-4o).',
                        'example' => 'claude-sonnet-4-5-20250929',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Human-readable model name.',
                        'example' => 'Claude Sonnet 4.5',
                    ],
                    'context_window' => [
                        'type' => 'integer',
                        'description' => 'Maximum context window size in tokens.',
                        'example' => 200000,
                    ],
                ],
            ],
            'ModelsResponse' => [
                'type' => 'object',
                'properties' => [
                    'resource' => [
                        'type' => 'array',
                        'description' => 'Array of available models.',
                        'items' => [
                            '$ref' => '#/components/schemas/AiModel',
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function handleGET()
    {
        /** @var AiConnection $service */
        $service = $this->getService();
        $provider = $service->getProvider();

        $models = $provider->listModels();

        // Filter by allowed_models if configured.
        $allowed = $service->getConfig('allowed_models');
        if (!empty($allowed)) {
            if (is_string($allowed)) {
                $allowed = json_decode($allowed, true) ?: [];
            }
            if (!empty($allowed)) {
                $models = array_values(array_filter($models, function ($m) use ($allowed) {
                    return in_array($m['id'] ?? '', $allowed, true);
                }));
            }
        }

        return ResourcesWrapper::wrapResources($models);
    }
}
