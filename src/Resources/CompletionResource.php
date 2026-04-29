<?php

namespace DreamFactory\Core\AI\Resources;

use DreamFactory\Core\AI\Providers\AiProviderInterface;
use DreamFactory\Core\AI\Providers\Streaming\SseRelay;
use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\AI\Services\RateLimiter;
use DreamFactory\Core\AI\Utility\UsageLogger;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Resources\BaseRestResource;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompletionResource extends BaseRestResource
{
    const RESOURCE_NAME = 'completion';

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);

        return [
            '/completion' => [
                'post' => [
                    'summary'     => 'Generate a single-turn text completion.',
                    'description' => 'Send a prompt and receive a generated text response from the configured AI provider.',
                    'operationId' => 'create' . $capitalized . 'Completion',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/CompletionRequest',
                    ],
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/CompletionResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocRequests()
    {
        return [
            'CompletionRequest' => [
                'description' => 'Completion request',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/CompletionRequest'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocResponses()
    {
        return [
            'CompletionResponse' => [
                'description' => 'Completion response',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/CompletionResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocSchemas()
    {
        return [
            'CompletionRequest' => [
                'type' => 'object',
                'required' => ['prompt'],
                'properties' => [
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'The text prompt to send to the AI provider.',
                        'example' => 'Explain what DreamFactory is in one sentence.',
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
                        'example' => 256,
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
            'CompletionResponse' => [
                'type' => 'object',
                'properties' => [
                    'content' => [
                        'type' => 'string',
                        'description' => 'The generated text.',
                        'example' => 'DreamFactory is an open-source API gateway that instantly generates REST APIs for any database or service.',
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
                        'example' => 14,
                    ],
                    'output_tokens' => [
                        'type' => 'integer',
                        'description' => 'Number of output tokens generated.',
                        'example' => 28,
                    ],
                    'finish_reason' => [
                        'type' => 'string',
                        'description' => 'Reason generation stopped (e.g. end_turn, max_tokens).',
                        'example' => 'end_turn',
                    ],
                    'latency_ms' => [
                        'type' => 'integer',
                        'description' => 'Round-trip latency in milliseconds.',
                        'example' => 1240,
                    ],
                ],
            ],
        ];
    }

    protected function handlePOST()
    {
        $payload = $this->getPayloadData();

        $this->validatePayload($payload);

        /** @var AiConnection $service */
        $service = $this->getService();
        $provider = $service->getProvider();

        RateLimiter::check($service->getServiceId(), $provider->getProviderName());

        if (!empty($payload['stream'])) {
            return $this->handleStream($service, $provider, $payload);
        }

        $start = hrtime(true);

        try {
            $result = $provider->complete([
                'prompt'      => $payload['prompt'],
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

    /**
     * Streaming branch — same shape as ChatResource::handleStream(), but
     * the upstream call is `chatStream()` with the prompt wrapped in a
     * single user message (mirrors how complete() builds its non-stream
     * call). Provider's `complete()` doesn't have a streaming twin since
     * the wire shape is identical to chat (prompt → one-message chat).
     */
    private function handleStream(AiConnection $service, AiProviderInterface $provider, array $payload): StreamedResponse
    {
        if (!$provider->supportsStreaming()) {
            throw new BadRequestException(
                sprintf('Provider "%s" does not support streaming.', $provider->getProviderName())
            );
        }

        $messages = [['role' => 'user', 'content' => $payload['prompt']]];
        $model = $payload['model'] ?? $service->getConfig('default_model', '');
        $chatId = 'cmpl-' . Str::random(24);
        $serviceId = $service->getServiceId();
        $providerName = $provider->getProviderName();
        $start = hrtime(true);

        return new StreamedResponse(function () use ($provider, $payload, $messages, $serviceId, $model, $providerName, $chatId, $start) {
            $totals = null;
            try {
                $events = $provider->chatStream($messages, [
                    'max_tokens'  => $payload['max_tokens'] ?? null,
                    'temperature' => $payload['temperature'] ?? null,
                    'model'       => $payload['model'] ?? null,
                ]);

                $totals = SseRelay::drive(
                    $events,
                    function (string $frame): bool {
                        echo $frame;
                        @ob_flush();
                        flush();
                        return !connection_aborted();
                    },
                    $providerName,
                    $model,
                    $chatId,
                );
            } catch (\Throwable $e) {
                echo 'data: ' . json_encode(['error' => ['message' => $e->getMessage()]]) . "\n\n";
                @ob_flush();
                flush();
                $totals = [
                    'status'        => 'error',
                    'input_tokens'  => 0,
                    'output_tokens' => 0,
                    'finish_reason' => null,
                    'error_message' => $e->getMessage(),
                ];
            } finally {
                $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
                $totals = $totals ?? [
                    'status'        => 'error',
                    'input_tokens'  => 0,
                    'output_tokens' => 0,
                    'finish_reason' => null,
                    'error_message' => 'streaming aborted before any totals were captured',
                ];

                $logShape = [
                    'provider'      => $providerName,
                    'model'         => $model,
                    'input_tokens'  => $totals['input_tokens'],
                    'output_tokens' => $totals['output_tokens'],
                ];

                if ($totals['status'] === 'success') {
                    UsageLogger::logSuccess($serviceId, self::RESOURCE_NAME, $logShape, $latencyMs);
                } elseif ($totals['status'] === 'partial') {
                    UsageLogger::logPartial(
                        $serviceId,
                        self::RESOURCE_NAME,
                        $logShape,
                        $latencyMs,
                        $totals['finish_reason'] ?? 'client_disconnect',
                    );
                } else {
                    UsageLogger::logError(
                        $serviceId,
                        self::RESOURCE_NAME,
                        $providerName,
                        $model,
                        $latencyMs,
                        $totals['error_message'] ?? 'unknown streaming error',
                    );
                }
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Validate completion request payload.
     *
     * @throws BadRequestException
     */
    private function validatePayload(array $payload): void
    {
        $prompt = $payload['prompt'] ?? null;

        if (!is_string($prompt) || trim($prompt) === '') {
            throw new BadRequestException('"prompt" is required and must be a non-empty string.');
        }

        if (strlen($prompt) > 100_000) {
            throw new BadRequestException('"prompt" exceeds the maximum length of 100,000 characters.');
        }

        if (isset($payload['max_tokens'])) {
            $maxTokens = $payload['max_tokens'];
            if (!is_int($maxTokens) && !is_numeric($maxTokens)) {
                throw new BadRequestException('"max_tokens" must be a positive integer.');
            }
            if ((int) $maxTokens < 1) {
                throw new BadRequestException('"max_tokens" must be a positive integer.');
            }
        }

        if (isset($payload['temperature'])) {
            $temp = $payload['temperature'];
            if (!is_numeric($temp)) {
                throw new BadRequestException('"temperature" must be a number between 0.0 and 2.0.');
            }
            if ((float) $temp < 0.0 || (float) $temp > 2.0) {
                throw new BadRequestException('"temperature" must be between 0.0 and 2.0.');
            }
        }
    }
}
