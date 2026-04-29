<?php

namespace DreamFactory\Core\AI\Resources;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use DreamFactory\Core\AI\Providers\AiProviderInterface;
use DreamFactory\Core\AI\Providers\Streaming\SseRelay;
use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\AI\Services\BudgetAlerter;
use DreamFactory\Core\AI\Services\BudgetEnforcer;
use DreamFactory\Core\AI\Services\FallbackChain;
use DreamFactory\Core\AI\Services\RateLimiter;
use DreamFactory\Core\AI\Utility\AuditDispatcher;
use DreamFactory\Core\AI\Utility\PromptLogger;
use DreamFactory\Core\AI\Utility\UsageLogger;
use Illuminate\Support\Facades\Log;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $this->validatePayload($payload);

        /** @var AiConnection $service */
        $service = $this->getService();
        $provider = $service->getProvider();

        RateLimiter::check($service->getServiceId(), $provider->getProviderName());

        // Pre-call budget check. Throws AiProviderException(429) when
        // any matching hard-stop budget is at or above its cap. The
        // attribution we pass is the request's CALLING context (admin
        // user etc.); attribution attached to outbound provider calls
        // is the same in the non-orchestrator path.
        BudgetEnforcer::check(
            $service->getServiceId(),
            Session::getCurrentUserId(),
            Session::getRoleId(),
            Session::get('app.id'),
        );

        if (!empty($payload['stream'])) {
            return $this->handleStream($service, $provider, $payload);
        }

        // Walk the configured fallback chain on retryable provider errors
        // (429 / 5xx / connection timeout). Each attempt writes its own
        // ai_usage_log row so cost + latency stay attributable per-provider.
        return FallbackChain::execute(
            $service->getServiceId(),
            fn(AiProviderInterface $attemptProvider, int $attemptServiceId, int $attemptIdx) =>
                $this->dispatchChat($attemptProvider, $attemptServiceId, $attemptIdx, $payload),
        );
    }

    /**
     * Dispatch a single attempt against an AI Connection. On
     * retryable failure, throws AiProviderException for FallbackChain
     * to catch + try the next provider. On non-retryable failure,
     * logs the error and re-throws.
     */
    private function dispatchChat(
        AiProviderInterface $provider,
        int $serviceId,
        int $attemptIdx,
        array $payload,
    ): array {
        $start = hrtime(true);

        try {
            $result = $provider->chat($payload['messages'], [
                'max_tokens'  => $payload['max_tokens'] ?? null,
                'temperature' => $payload['temperature'] ?? null,
                'model'       => $payload['model'] ?? null,
            ]);

            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $result['latency_ms'] = $latencyMs;

            UsageLogger::logSuccess($serviceId, self::RESOURCE_NAME, $result, $latencyMs);

            PromptLogger::record(
                $serviceId,
                self::RESOURCE_NAME,
                $result['provider'] ?? '',
                $result['model'] ?? '',
                json_encode($payload['messages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                (string) ($result['content'] ?? ''),
                UsageLogger::requestId(),
                'success',
            );

            AuditDispatcher::dispatch($serviceId, UsageLogger::requestId());

            // Post-call budget threshold detection. Fires webhooks when
            // a budget crosses 50/80/100% — once per threshold per period.
            BudgetAlerter::afterCall(
                $serviceId,
                Session::getCurrentUserId(),
                Session::getRoleId(),
                Session::get('app.id'),
            );

            if ($attemptIdx > 0) {
                Log::info("AI fallback attempt #{$attemptIdx} succeeded on service {$serviceId}", [
                    'request_id' => UsageLogger::requestId(),
                ]);
            }
            return $result;
        } catch (\Throwable $e) {
            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            UsageLogger::logError(
                $serviceId,
                self::RESOURCE_NAME,
                $provider->getProviderName(),
                $payload['model'] ?? '',
                $latencyMs,
                $e->getMessage(),
            );
            throw $e;
        }
    }

    /**
     * Streaming branch — opens an SSE response, drives the provider's
     * chatStream() generator through SseRelay, and writes a single
     * AiUsageLog row in a try/finally so partial disconnects still bill.
     *
     * Returns OpenAI-shape SSE frames regardless of upstream provider so
     * any OpenAI-compatible SDK can consume the stream unchanged.
     */
    private function handleStream(AiConnection $service, AiProviderInterface $provider, array $payload): StreamedResponse
    {
        if (!$provider->supportsStreaming()) {
            throw new BadRequestException(
                sprintf('Provider "%s" does not support streaming.', $provider->getProviderName())
            );
        }

        $model = $payload['model'] ?? $service->getConfig('default_model', '');
        $chatId = 'chatcmpl-' . Str::random(24);
        $serviceId = $service->getServiceId();
        $providerName = $provider->getProviderName();
        $start = hrtime(true);

        $response = new StreamedResponse(function () use ($provider, $payload, $serviceId, $model, $providerName, $chatId, $start) {
            $totals = null;
            try {
                $events = $provider->chatStream($payload['messages'], [
                    'max_tokens'  => $payload['max_tokens'] ?? null,
                    'temperature' => $payload['temperature'] ?? null,
                    'model'       => $payload['model'] ?? null,
                ]);

                $totals = SseRelay::drive(
                    $events,
                    function (string $frame): bool {
                        echo $frame;
                        // ob_flush() may throw a notice if no buffer is
                        // active; guard with @ since the Laravel test
                        // harness can run without an output buffer.
                        @ob_flush();
                        flush();
                        // If the client closed the connection, stop pulling
                        // more bytes from the provider.
                        return !connection_aborted();
                    },
                    $providerName,
                    $model,
                    $chatId,
                );
            } catch (\Throwable $e) {
                // Provider couldn't even open the upstream stream — surface
                // it inline as an error frame so SDK clients see something,
                // and synthesize totals so the finally block can log it.
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
                // ALWAYS log — half-billed dropped streams are the worst
                // class of billing bug a gateway can ship.
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
            // nginx-specific: disable proxy buffering so frames reach the
            // client as they're emitted. Most other reverse proxies honor
            // Cache-Control: no-transform, but X-Accel-Buffering is the
            // belt-and-suspenders for nginx-fronted DF installs.
            'X-Accel-Buffering' => 'no',
        ]);

        return $response;
    }

    private static array $validRoles = ['system', 'user', 'assistant'];

    /**
     * Validate chat request payload.
     *
     * @throws BadRequestException
     */
    private function validatePayload(array $payload): void
    {
        $messages = $payload['messages'] ?? null;

        if (!is_array($messages) || empty($messages)) {
            throw new BadRequestException('"messages" must be a non-empty array.');
        }

        foreach ($messages as $i => $message) {
            if (!is_array($message)) {
                throw new BadRequestException("messages[{$i}] must be an object with \"role\" and \"content\".");
            }

            $role = $message['role'] ?? null;
            if (!is_string($role) || !in_array($role, self::$validRoles, true)) {
                throw new BadRequestException(
                    "messages[{$i}].role must be one of: " . implode(', ', self::$validRoles) . "."
                );
            }

            $content = $message['content'] ?? null;
            if (!is_string($content) || trim($content) === '') {
                throw new BadRequestException("messages[{$i}].content must be a non-empty string.");
            }
        }

        if (isset($payload['max_tokens'])) {
            if (!is_numeric($payload['max_tokens']) || (int) $payload['max_tokens'] < 1) {
                throw new BadRequestException('"max_tokens" must be a positive integer.');
            }
        }

        if (isset($payload['temperature'])) {
            if (!is_numeric($payload['temperature']) || (float) $payload['temperature'] < 0.0 || (float) $payload['temperature'] > 2.0) {
                throw new BadRequestException('"temperature" must be between 0.0 and 2.0.');
            }
        }
    }
}
