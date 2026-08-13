<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Http\Controllers;

use DreamFactory\Core\AI\Exceptions\AiProviderException;
use DreamFactory\Core\AI\Providers\AiProviderFactory;
use DreamFactory\Core\AI\Providers\AiProviderInterface;
use DreamFactory\Core\AI\Providers\Streaming\SseRelay;
use DreamFactory\Core\AI\Services\BudgetAlerter;
use DreamFactory\Core\AI\Services\BudgetEnforcer;
use DreamFactory\Core\AI\Services\FallbackChain;
use DreamFactory\Core\AI\Services\ModelAliasResolver;
use DreamFactory\Core\AI\Services\RateLimiter;
use DreamFactory\Core\AI\Utility\AuditDispatcher;
use DreamFactory\Core\AI\Utility\OpenAiResponseFormatter;
use DreamFactory\Core\AI\Utility\PromptLogger;
use DreamFactory\Core\AI\Utility\UsageLogger;
use DreamFactory\Core\Enums\ServiceRequestorTypes;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * OpenAI-compatible HTTP gateway for the AI Connection layer.
 *
 * Implements the subset of the OpenAI API that customer apps actually
 * use as a drop-in proxy:
 *
 *   POST /api/v2/_ai/v1/chat/completions
 *     - sync + streaming (stream:true) chat completions
 *     - model-alias-driven routing to any configured AI Connection
 *     - drops into existing rate limiting, usage logging, prompt audit,
 *       SIEM dispatch, and fallback-chain machinery
 *
 *   GET /api/v2/_ai/v1/models
 *     - lists every active model alias in OpenAI shape, for SDKs that
 *       call models.list() at startup
 *
 * **The drop-in pitch:**
 *
 *   client = OpenAI(
 *       api_key="<DF API key>",
 *       base_url="https://df.example/api/v2/_ai/v1",
 *   )
 *   client.chat.completions.create(model="claude-sonnet", messages=[...])
 *
 * Everything the existing /api/v2/<svc>/chat endpoint enforces (rate
 * limits, prompt logging, role-based RBAC, fallback chains) applies
 * here too — just via the alias routing instead of an explicit service.
 */
class OpenAiCompatController extends Controller
{
    private const RESOURCE_NAME = 'openai_compat';

    /**
     * POST /api/v2/_ai/v1/chat/completions — main entry point.
     *
     * Accepts the OpenAI request shape (model + messages [+ stream,
     * temperature, max_tokens, ...]), resolves the alias, dispatches
     * via the configured AI Connection's provider, and wraps the
     * result in OpenAI shape on the way back.
     *
     * Streaming (stream:true) returns a Symfony StreamedResponse with
     * OpenAI-shape SSE frames — SseRelay already emits exactly that
     * shape, so the streaming compat path is just routing.
     */
    public function chatCompletions(Request $request): mixed
    {
        $body = $request->all();
        $model    = (string) ($body['model']    ?? '');
        $messages = $body['messages'] ?? null;

        if ($model === '') {
            return $this->errorResponse(400, '"model" is required.', 'invalid_request_error');
        }
        if (!is_array($messages) || empty($messages)) {
            return $this->errorResponse(400, '"messages" must be a non-empty array.', 'invalid_request_error');
        }

        try {
            $alias = ModelAliasResolver::resolve($model);
        } catch (NotFoundException $e) {
            return $this->errorResponse(404, $e->getMessage(), 'invalid_request_error', 'model_not_found');
        }

        $serviceId  = $alias['service_id'];
        $aliasName  = $alias['alias_name'];

        // RBAC. The route carries only df.auth_check, which resolves
        // identity but does NOT reject anonymous or role-scoped callers.
        // The native /api/v2/<svc>/chat path is guarded per-service by
        // AccessCheck; this custom route skips it, so we enforce the same
        // per-service grant here. checkServicePermission intersects the
        // POST verb bit with the caller's own role mask for this exact
        // AI Connection and throws ForbiddenException (403) otherwise.
        // A caller with no role (anonymous) gets an empty mask and is
        // rejected. A caller whose role grants a different service, or
        // grants GET but not POST on this one, is rejected by the bit.
        $serviceName = \ServiceManager::getServiceNameById($serviceId);
        if (!$serviceName) {
            return $this->errorResponse(404, "Model '{$model}' is not configured.", 'invalid_request_error', 'model_not_found');
        }
        try {
            Session::checkServicePermission(Verbs::POST, $serviceName);
        } catch (ForbiddenException $e) {
            return $this->errorResponse(403, $e->getMessage(), 'invalid_request_error', 'insufficient_permissions');
        }

        // Rate limit against the resolved service. We deliberately
        // check ONLY the primary — fallbacks have their own per-service
        // rate limits applied at their own dispatch.
        try {
            $primaryProvider = AiProviderFactory::fromServiceId($serviceId);
            RateLimiter::check($serviceId, $primaryProvider->getProviderName());
            BudgetEnforcer::check(
                $serviceId,
                Session::getCurrentUserId(),
                Session::getRoleId(),
                Session::get('app.id'),
            );
        } catch (AiProviderException $e) {
            return $this->errorResponse(
                $e->getHttpStatus() ?: 429,
                $e->getMessage(),
                'rate_limit_exceeded',
            );
        }

        // Force the physical model the alias points at, regardless of
        // what the alias was. Stops clients from bypassing the alias
        // by passing the physical name directly (which would skip the
        // admin's intended routing).
        $providerOptions = [
            'model'       => $alias['physical_model'],
            'max_tokens'  => $body['max_tokens']  ?? null,
            'temperature' => $body['temperature'] ?? null,
        ];

        if (!empty($body['stream'])) {
            return $this->streamResponse($serviceId, $aliasName, $messages, $providerOptions);
        }

        return $this->syncResponse($serviceId, $aliasName, $messages, $providerOptions);
    }

    /**
     * GET /api/v2/_ai/v1/models — list active aliases in OpenAI shape.
     *
     * OpenAI SDK clients call models.list() for autodiscovery. The shape:
     *   { object: "list", data: [{ id, object, created, owned_by }, ...] }
     */
    public function listModels(): JsonResponse
    {
        // Only advertise aliases whose AI Connection the caller's role
        // can GET. Same per-service RBAC as chatCompletions, applied as a
        // non-throwing filter so authorized callers still see their own
        // models. Anonymous or unscoped callers get an empty list rather
        // than an enumeration of every configured model.
        $data = [];
        foreach (ModelAliasResolver::listForOpenAiModels() as $model) {
            $serviceId = $model['service_id'] ?? null;
            unset($model['service_id']);
            if ($serviceId === null) {
                continue;
            }
            $serviceName = \ServiceManager::getServiceNameById((int) $serviceId);
            if (!$serviceName) {
                continue;
            }
            if (Session::checkServicePermission(Verbs::GET, $serviceName, null, ServiceRequestorTypes::API, false)) {
                $data[] = $model;
            }
        }

        return response()->json([
            'object' => 'list',
            'data'   => $data,
        ]);
    }

    // ─── Internals ────────────────────────────────────────────────────

    /**
     * Handle a non-streaming chat completion. Walks the fallback chain
     * just like ChatResource — every attempt produces its own
     * ai_usage_log row keyed by the same request_id.
     */
    private function syncResponse(int $serviceId, string $aliasName, array $messages, array $providerOptions): JsonResponse
    {
        try {
            $result = FallbackChain::execute(
                $serviceId,
                fn(AiProviderInterface $provider, int $svcId, int $idx) =>
                    $this->runSyncDispatch($provider, $svcId, $idx, $messages, $providerOptions),
            );
        } catch (AiProviderException $e) {
            return $this->errorResponse(
                $this->statusForException($e),
                $e->getMessage(),
                'api_error',
            );
        }

        return response()->json(
            OpenAiResponseFormatter::fromChatResult($result, $aliasName)
        );
    }

    /**
     * Single-attempt sync dispatch. Same shape as ChatResource::dispatchChat
     * but tagged with `openai_compat` resource so dashboard breakdowns
     * can split this traffic from native /api/v2/<svc>/chat callers.
     */
    private function runSyncDispatch(
        AiProviderInterface $provider,
        int $serviceId,
        int $attemptIdx,
        array $messages,
        array $providerOptions,
    ): array {
        $start = hrtime(true);
        try {
            $result = $provider->chat($messages, $providerOptions);
            $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);
            $result['latency_ms'] = $latencyMs;

            UsageLogger::logSuccess($serviceId, self::RESOURCE_NAME, $result, $latencyMs);
            PromptLogger::record(
                $serviceId,
                self::RESOURCE_NAME,
                $result['provider'] ?? '',
                $result['model'] ?? '',
                json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                (string) ($result['content'] ?? ''),
                UsageLogger::requestId(),
                'success',
            );
            AuditDispatcher::dispatch($serviceId, UsageLogger::requestId());

            BudgetAlerter::afterCall(
                $serviceId,
                Session::getCurrentUserId(),
                Session::getRoleId(),
                Session::get('app.id'),
            );

            if ($attemptIdx > 0) {
                Log::info("OpenAI-compat fallback attempt #{$attemptIdx} succeeded", [
                    'service_id' => $serviceId,
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
                (string) ($providerOptions['model'] ?? ''),
                $latencyMs,
                $e->getMessage(),
            );
            throw $e;
        }
    }

    /**
     * Handle a streaming chat completion. SseRelay already emits
     * OpenAI-shape SSE frames natively (chat.completion.chunk), so
     * we route the provider's chatStream generator straight through
     * with the same try/finally billing pattern ChatResource uses.
     */
    private function streamResponse(int $serviceId, string $aliasName, array $messages, array $providerOptions): StreamedResponse
    {
        $primaryProvider = AiProviderFactory::fromServiceId($serviceId);
        if (!$primaryProvider->supportsStreaming()) {
            return new StreamedResponse(function () {
                echo 'data: ' . json_encode(OpenAiResponseFormatter::errorEnvelope(
                    'The model alias resolves to a provider that does not support streaming.',
                    'invalid_request_error',
                )) . "\n\n";
            }, 400, ['Content-Type' => 'text/event-stream']);
        }

        $providerName = $primaryProvider->getProviderName();
        $physical     = (string) ($providerOptions['model'] ?? '');
        $chatId       = 'chatcmpl-' . Str::random(24);
        $start        = hrtime(true);

        return new StreamedResponse(function () use ($primaryProvider, $messages, $providerOptions, $serviceId, $providerName, $physical, $chatId, $start, $aliasName) {
            $totals = null;
            try {
                $events = $primaryProvider->chatStream($messages, $providerOptions);
                $totals = SseRelay::drive(
                    $events,
                    function (string $frame): bool {
                        echo $frame;
                        @ob_flush();
                        flush();
                        return !connection_aborted();
                    },
                    $providerName,
                    $aliasName,    // echo back the alias as `model`, not the physical
                    $chatId,
                );
            } catch (\Throwable $e) {
                echo 'data: ' . json_encode(OpenAiResponseFormatter::errorEnvelope(
                    $e->getMessage(),
                    'api_error',
                )) . "\n\n";
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
                    'status' => 'error', 'input_tokens' => 0, 'output_tokens' => 0,
                    'finish_reason' => null, 'error_message' => 'streaming aborted',
                ];

                $logShape = [
                    'provider'      => $providerName,
                    'model'         => $physical,
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
                        $physical,
                        $latencyMs,
                        $totals['error_message'] ?? 'unknown streaming error',
                    );
                }

                if ($totals['status'] !== 'error') {
                    PromptLogger::record(
                        $serviceId,
                        self::RESOURCE_NAME,
                        $providerName,
                        $physical,
                        json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                        '', // streaming response content isn't reassembled here
                        UsageLogger::requestId(),
                        $totals['status'],
                    );
                    AuditDispatcher::dispatch($serviceId, UsageLogger::requestId());
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
     * @return JsonResponse with OpenAI-shape error body
     */
    private function errorResponse(int $status, string $message, string $type, ?string $code = null): JsonResponse
    {
        return response()->json(
            OpenAiResponseFormatter::errorEnvelope($message, $type, $code),
            $status,
        );
    }

    private function statusForException(AiProviderException $e): int
    {
        $http = $e->getHttpStatus();
        return $http >= 400 && $http < 600 ? $http : 502;
    }
}
