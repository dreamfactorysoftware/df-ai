<?php

namespace DreamFactory\Core\AI\Resources;

use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\Resources\BaseRestResource;

class HealthResource extends BaseRestResource
{
    const RESOURCE_NAME = 'health';

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);

        return [
            '/health' => [
                'get' => [
                    'summary'     => 'Check AI provider health and availability.',
                    'description' => 'Returns whether the provider is reachable and measures response latency.',
                    'operationId' => 'get' . $capitalized . 'Health',
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/HealthResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocResponses()
    {
        return [
            'HealthResponse' => [
                'description' => 'Health check response',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/HealthResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocSchemas()
    {
        return [
            'HealthResponse' => [
                'type' => 'object',
                'properties' => [
                    'available' => [
                        'type' => 'boolean',
                        'description' => 'Whether the AI provider is reachable.',
                        'example' => true,
                    ],
                    'provider' => [
                        'type' => 'string',
                        'description' => 'Provider name.',
                        'example' => 'anthropic',
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'Default model configured for this service.',
                        'example' => 'claude-sonnet-4-5-20250929',
                    ],
                    'model_count' => [
                        'type' => 'integer',
                        'description' => 'Number of models available from the provider.',
                        'example' => 42,
                        'nullable' => true,
                    ],
                    'latency_ms' => [
                        'type' => 'integer',
                        'description' => 'Round-trip latency in milliseconds.',
                        'example' => 245,
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

        $start = hrtime(true);
        $available = false;
        $modelCount = null;

        try {
            $available = $provider->isAvailable();

            // Use listModels() for a lightweight connectivity check that
            // doesn't consume any tokens (unlike the previous complete() call).
            if ($available) {
                $models = $provider->listModels();
                $modelCount = count($models);
            }
        } catch (\Throwable) {
            // Provider unreachable — we still report the status.
        }

        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return [
            'available'   => $available,
            'provider'    => $provider->getProviderName(),
            'model'       => $service->getConfig('default_model', ''),
            'model_count' => $modelCount,
            'latency_ms'  => $latencyMs,
        ];
    }
}
