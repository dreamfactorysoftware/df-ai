<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Http\Controllers;

use DreamFactory\Core\AI\Providers\AiProviderFactory;
use DreamFactory\Core\AI\Utility\AuditStreamFormatter;
use DreamFactory\Core\AI\Utility\FilterRequestParser;
use DreamFactory\Core\AI\Utility\UsageAggregator;
use DreamFactory\Core\AI\Utility\UsageRates;
use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-only endpoints under /_internal/ai/* that don't fit the normal
 * DreamFactory service-routing pattern. Pulled out of ServiceProvider so
 * the closures don't bloat the SP and the handlers can be unit-tested.
 */
class InternalUsageController extends Controller
{
    /**
     * POST /_internal/ai/test-connection — try a provider's listModels()
     * with a candidate config. Used by the AI Connection edit form to
     * validate credentials BEFORE the service is saved.
     */
    public function testConnection(Request $request): JsonResponse
    {
        if (!Session::isSysAdmin()) {
            return response()->json(['error' => ['message' => 'Admin access required.']], 403);
        }

        $config = $request->only([
            'provider', 'api_key', 'base_url', 'organization_id',
            'extra_headers', 'timeout',
        ]);

        // The protection mask the edit form redisplays for saved secrets.
        // Mirrors Protectable::$protectionMask in df-core.
        $protectionMask = '**********';

        // When editing an existing AI Connection, the form redisplays the
        // saved `api_key` as the protection mask (it never re-sends the real
        // secret), so the request arrives carrying '**********'. If the form
        // passes its `service_id`, fall back to the saved config for any
        // field the form left empty OR sent as the mask. Lets admins click
        // "Test Connection" / "Get models" without re-entering secrets.
        $serviceId = $request->input('service_id');
        if ($serviceId) {
            $saved = \DreamFactory\Core\AI\Models\AiConnectionConfig::query()
                ->where('service_id', (int) $serviceId)
                ->first();
            if ($saved) {
                // Read the real, decrypted secrets rather than the masked
                // view the model returns by default (protectedView=true).
                $saved->protectedView = false;
                foreach (['provider', 'api_key', 'base_url', 'organization_id', 'extra_headers'] as $key) {
                    $incoming = $config[$key] ?? null;
                    $isBlankOrMasked = empty($incoming) || $incoming === $protectionMask;
                    if ($isBlankOrMasked && !empty($saved->{$key})) {
                        $config[$key] = $saved->{$key};
                    }
                }
            }
        }

        if (empty($config['provider'])) {
            return response()->json(['error' => ['message' => 'Provider is required.']], 422);
        }

        try {
            $provider = AiProviderFactory::make($config);
            return response()->json([
                'success'  => true,
                'provider' => $config['provider'],
                'resource' => $provider->listModels(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error'   => ['message' => $e->getMessage()],
            ], 400);
        }
    }

    /**
     * GET /_internal/ai/usage — org-wide AI usage aggregation. Powers the
     * Gateway dashboard's AI section. See UsageAggregator::FILTER_KEYS for
     * the filter contract. Pass ?compare=1 to also include a `previous`
     * block summarizing the immediately-preceding window of equal length —
     * powers the period-over-period delta on each summary tile.
     */
    public function usage(Request $request): JsonResponse
    {
        if (!Session::isSysAdmin()) {
            return response()->json(['error' => ['message' => 'Admin access required.']], 403);
        }

        $period = (string) $request->get('period', '7d');
        $since = UsageAggregator::parsePeriod($period);
        $driver = \DB::connection()->getDriverName();
        $filters = FilterRequestParser::parse($request, UsageAggregator::FILTER_KEYS);

        $result = UsageAggregator::aggregate($since, $driver, $filters);
        $result['period'] = $period;
        $result['budgets'] = UsageAggregator::budgetStatus();
        $result['default_rates'] = UsageRates::defaultRatesForApi();

        if ($request->boolean('compare')) {
            // Previous window = same duration immediately before `since`.
            // Use raw timestamp arithmetic; Carbon::diffInSeconds is signed in
            // Carbon 3 and was getting the bounds backwards.
            $now = \Illuminate\Support\Carbon::now();
            $windowSeconds = max(1, $now->getTimestamp() - $since->getTimestamp());
            $prevUntil = $since->copy();
            $prevSince = $since->copy()->subSeconds($windowSeconds);
            $result['previous'] = UsageAggregator::summarize($prevSince, $filters, $prevUntil);
        }

        return response()->json($result);
    }

    /**
     * GET /_internal/ai/audit-stream
     *
     * Newline-delimited JSON (NDJSON) stream of ECS-shaped audit events
     * for SIEM ingestion. Designed for Logstash's `http_poller` input
     * plugin or any HTTP-pull pipeline (Datadog HTTP, Splunk's HTTP
     * input, custom).
     *
     * Query params:
     *   since   ISO-8601 timestamp (default: 5 minutes ago)
     *   until   ISO-8601 timestamp (default: now)
     *   limit   max events (default 1000, capped at 5000)
     *
     * The pull pattern is the simplest SIEM integration — a customer's
     * SOC team configures their Logstash to poll this endpoint every
     * minute with `since=<last_max_timestamp>`. They get every AI event
     * including the redacted prompt content (when prompt logging is on
     * for the source AI Connection).
     *
     * For push-based SIEM integration (Splunk HEC, Datadog logs, etc.)
     * see {@see AuditDispatcher} which fires per-event webhooks.
     *
     * Auth: admin-only via Session::isSysAdmin(). Real deployments will
     * want a service-account approach (long-lived API key for the SIEM)
     * rather than session token; covered by the existing API key system.
     */
    public function auditStream(Request $request): StreamedResponse
    {
        if (!Session::isSysAdmin()) {
            return new StreamedResponse(function () {
                echo json_encode(['error' => ['message' => 'Admin access required.']]);
            }, 403, ['Content-Type' => 'application/json']);
        }

        $sinceStr = (string) $request->get('since', '');
        $untilStr = (string) $request->get('until', '');
        $limit    = min(max((int) $request->get('limit', 1000), 1), 5000);

        $since = $sinceStr !== ''
            ? Carbon::parse($sinceStr)
            : Carbon::now()->subMinutes(5);
        $until = $untilStr !== '' ? Carbon::parse($untilStr) : null;

        return new StreamedResponse(function () use ($since, $until, $limit) {
            // Stream NDJSON: one JSON object per line, no enclosing array.
            // Logstash's http_poller + json_lines codec consumes this
            // directly. Same shape Splunk's HTTP input + json_no_brace
            // sourcetype handles, and Datadog's HTTP intake.
            foreach (AuditStreamFormatter::streamForWindow($since, $until, $limit) as $event) {
                echo AuditStreamFormatter::toNdjsonLine($event), "\n";
                @ob_flush();
                flush();
                if (connection_aborted()) {
                    return;
                }
            }
        }, 200, [
            'Content-Type'      => 'application/x-ndjson',
            'Cache-Control'     => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
