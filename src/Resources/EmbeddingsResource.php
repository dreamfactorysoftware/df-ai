<?php

namespace DreamFactory\Core\AI\Resources;

use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\AI\Utility\UsageLogger;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Resources\BaseRestResource;

class EmbeddingsResource extends BaseRestResource
{
    const RESOURCE_NAME = 'embeddings';

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);

        return [
            '/embeddings' => [
                'post' => [
                    'summary'     => 'Generate text embeddings.',
                    'description' => 'Send text input and receive vector embeddings from the AI provider. Not all providers support embeddings.',
                    'operationId' => 'create' . $capitalized . 'Embeddings',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/EmbeddingsRequest',
                    ],
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/EmbeddingsResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocRequests()
    {
        return [
            'EmbeddingsRequest' => [
                'description' => 'Embeddings request',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/EmbeddingsRequest'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocResponses()
    {
        return [
            'EmbeddingsResponse' => [
                'description' => 'Embeddings response',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/EmbeddingsResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocSchemas()
    {
        return [
            'EmbeddingsRequest' => [
                'type' => 'object',
                'required' => ['input'],
                'properties' => [
                    'input' => [
                        'description' => 'Text string or array of strings to embed.',
                        'oneOf' => [
                            ['type' => 'string'],
                            ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'example' => ['DreamFactory is an API gateway.', 'It supports many databases.'],
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'Embedding model to use. Overrides the service default. Note: not all providers support embeddings.',
                        'example' => 'text-embedding-3-small',
                    ],
                ],
            ],
            'EmbeddingsResponse' => [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'description' => 'Array of embedding objects.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'embedding' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'number', 'format' => 'float'],
                                    'description' => 'The embedding vector.',
                                ],
                                'index' => [
                                    'type' => 'integer',
                                    'description' => 'Index of the input text this embedding corresponds to.',
                                ],
                            ],
                        ],
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'Model used for embedding.',
                        'example' => 'text-embedding-3-small',
                    ],
                    'usage' => [
                        'type' => 'object',
                        'properties' => [
                            'prompt_tokens' => ['type' => 'integer', 'example' => 12],
                            'total_tokens' => ['type' => 'integer', 'example' => 12],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function handlePOST()
    {
        $payload = $this->getPayloadData();
        $input = $payload['input'] ?? null;

        if (empty($input)) {
            throw new BadRequestException('"input" (string or array of strings) is required.');
        }

        /** @var AiConnection $service */
        $service = $this->getService();
        $provider = $service->getProvider();
        $start = hrtime(true);

        try {
            $result = $provider->embeddings($input, [
                'model' => $payload['model'] ?? null,
            ]);

            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

            UsageLogger::logSuccess($service->getServiceId(), self::RESOURCE_NAME, [
                'provider'      => $provider->getProviderName(),
                'model'         => $result['model'] ?? '',
                'input_tokens'  => $result['usage']['prompt_tokens'] ?? $result['usage']['total_tokens'] ?? 0,
                'output_tokens' => 0,
            ], $latencyMs);

            return $result;
        } catch (\Throwable $e) {
            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            UsageLogger::logError(
                $service->getServiceId(),
                self::RESOURCE_NAME,
                $provider->getProviderName(),
                $payload['model'] ?? $service->getConfig('default_model', ''),
                $latencyMs,
                $e->getMessage(),
            );
            throw $e;
        }
    }
}
