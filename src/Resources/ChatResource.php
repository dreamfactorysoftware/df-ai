<?php

namespace DreamFactory\Core\AI\Resources;

use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\AI\Utility\UsageLogger;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Resources\BaseRestResource;

class ChatResource extends BaseRestResource
{
    const RESOURCE_NAME = 'chat';

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);

        return [
            '/chat' => [
                'post' => [
                    'summary'     => 'Send a multi-turn chat conversation.',
                    'description' => 'Send an array of messages (system, user, assistant) and receive an AI-generated response.',
                    'operationId' => 'create' . $capitalized . 'Chat',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/ChatRequest',
                    ],
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/ChatResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocRequests()
    {
        return [
            'ChatRequest' => [
                'description' => 'Chat request',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ChatRequest'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocResponses()
    {
        return [
            'ChatResponse' => [
                'description' => 'Chat response',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ChatResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocSchemas()
    {
        return [
            'ChatMessage' => [
                'type' => 'object',
                'required' => ['role', 'content'],
                'properties' => [
                    'role' => [
                        'type' => 'string',
                        'enum' => ['system', 'user', 'assistant'],
                        'description' => 'The role of the message author.',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'The message content.',
                    ],
                ],
            ],
            'ChatRequest' => [
                'type' => 'object',
                'required' => ['messages'],
                'properties' => [
                    'messages' => [
                        'type' => 'array',
                        'description' => 'Array of conversation messages.',
                        'items' => [
                            '$ref' => '#/components/schemas/ChatMessage',
                        ],
                        'example' => [
                            ['role' => 'system', 'content' => 'You are a helpful assistant that knows about DreamFactory.'],
                            ['role' => 'user', 'content' => 'What databases does DreamFactory support?'],
                        ],
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'Model to use. Overrides the service default.',
                        'example' => 'claude-sonnet-4-5-20250929',
                    ],
                    'max_tokens' => [
                        'type' => 'integer',
                        'description' => 'Maximum tokens in the response.',
                        'default' => 1024,
                        'example' => 512,
                    ],
                    'temperature' => [
                        'type' => 'number',
                        'format' => 'float',
                        'description' => 'Sampling temperature (0.0–2.0). Lower = more deterministic.',
                        'default' => 0.7,
                        'example' => 0.7,
                    ],
                ],
            ],
            'ChatResponse' => [
                'type' => 'object',
                'properties' => [
                    'content' => [
                        'type' => 'string',
                        'description' => 'The generated response text.',
                        'example' => 'DreamFactory supports MySQL, PostgreSQL, SQL Server, Oracle, MongoDB, Snowflake, BigQuery, and many more.',
                    ],
                    'provider' => [
                        'type' => 'string',
                        'description' => 'Provider that handled the request.',
                        'example' => 'anthropic',
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'Model used for generation.',
                        'example' => 'claude-sonnet-4-5-20250929',
                    ],
                    'input_tokens' => [
                        'type' => 'integer',
                        'description' => 'Number of input tokens consumed.',
                        'example' => 34,
                    ],
                    'output_tokens' => [
                        'type' => 'integer',
                        'description' => 'Number of output tokens generated.',
                        'example' => 42,
                    ],
                    'finish_reason' => [
                        'type' => 'string',
                        'description' => 'Reason generation stopped.',
                        'example' => 'end_turn',
                    ],
                    'latency_ms' => [
                        'type' => 'integer',
                        'description' => 'Round-trip latency in milliseconds.',
                        'example' => 1850,
                    ],
                ],
            ],
        ];
    }

    protected function handlePOST()
    {
        $payload = $this->getPayloadData();
        $messages = $payload['messages'] ?? null;

        if (empty($messages) || !is_array($messages)) {
            throw new BadRequestException('"messages" array is required.');
        }

        /** @var AiConnection $service */
        $service = $this->getService();
        $provider = $service->getProvider();
        $start = hrtime(true);

        try {
            $result = $provider->chat($messages, [
                'max_tokens'  => $payload['max_tokens'] ?? null,
                'temperature' => $payload['temperature'] ?? null,
                'model'       => $payload['model'] ?? null,
            ]);

            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $result['latency_ms'] = $latencyMs;

            UsageLogger::logSuccess($service->getServiceId(), self::RESOURCE_NAME, $result, $latencyMs);

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
