<?php

declare(strict_types=1);

namespace DreamFactory\Core\AI\Http\Controllers;

use DreamFactory\Core\AI\Providers\AiProviderFactory;
use DreamFactory\Core\AI\Utility\FilterRequestParser;
use DreamFactory\Core\AI\Utility\UsageAggregator;
use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * the filter contract.
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

        return response()->json($result);
    }
}
