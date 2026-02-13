<?php

namespace DreamFactory\Core\AI\Resources;

use DreamFactory\Core\AI\Models\AiUsageLog;
use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\Resources\BaseRestResource;
use Illuminate\Support\Carbon;

class UsageResource extends BaseRestResource
{
    const RESOURCE_NAME = 'usage';

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);

        return [
            '/usage' => [
                'get' => [
                    'summary'     => 'Retrieve usage statistics for this AI connection.',
                    'description' => 'Returns aggregated token usage, request counts, latency, and error stats. Supports period filtering.',
                    'operationId' => 'get' . $capitalized . 'Usage',
                    'parameters' => [
                        [
                            'name' => 'period',
                            'description' => 'Time period to query. Examples: 24h, 7d, 30d, 90d.',
                            'schema' => ['type' => 'string', 'default' => '24h', 'example' => '7d'],
                            'in' => 'query',
                        ],
                    ],
                    'responses' => [
                        '200' => ['$ref' => '#/components/responses/UsageResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocResponses()
    {
        return [
            'UsageResponse' => [
                'description' => 'Usage statistics response',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/UsageResponse'],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocSchemas()
    {
        return [
            'UsageResponse' => [
                'type' => 'object',
                'properties' => [
                    'total_requests' => [
                        'type' => 'integer',
                        'description' => 'Total number of requests in the period.',
                        'example' => 1547,
                    ],
                    'total_input_tokens' => [
                        'type' => 'integer',
                        'description' => 'Total input tokens consumed.',
                        'example' => 234567,
                    ],
                    'total_output_tokens' => [
                        'type' => 'integer',
                        'description' => 'Total output tokens generated.',
                        'example' => 89012,
                    ],
                    'avg_latency_ms' => [
                        'type' => 'integer',
                        'description' => 'Average response latency in milliseconds.',
                        'example' => 1340,
                    ],
                    'errors' => [
                        'type' => 'integer',
                        'description' => 'Number of failed requests.',
                        'example' => 3,
                    ],
                    'period' => [
                        'type' => 'string',
                        'description' => 'The time period queried.',
                        'example' => '24h',
                    ],
                    'since' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'Start of the query period (ISO 8601).',
                        'example' => '2026-02-10T12:00:00+00:00',
                    ],
                    'by_model' => [
                        'type' => 'object',
                        'description' => 'Breakdown of usage by model.',
                    ],
                    'by_user' => [
                        'type' => 'array',
                        'description' => 'Top 10 users by request count.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'user_id' => ['type' => 'integer', 'example' => 1],
                                'requests' => ['type' => 'integer', 'example' => 800],
                                'input_tokens' => ['type' => 'integer', 'example' => 120000],
                            ],
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
        $serviceId = $service->getServiceId();

        // Default to last 24 hours. Allow ?period=7d, 30d, 24h, etc.
        $period = $this->request->getParameter('period', '24h');
        $since = $this->parsePeriod($period);

        $query = AiUsageLog::where('service_id', $serviceId)
            ->where('created_at', '>=', $since);

        $totalRequests = (clone $query)->count();
        $totalInputTokens = (clone $query)->sum('input_tokens');
        $totalOutputTokens = (clone $query)->sum('output_tokens');
        $avgLatency = (clone $query)->avg('latency_ms');
        $errorCount = (clone $query)->where('status', 'error')->count();

        // By model
        $byModel = (clone $query)
            ->selectRaw('model, COUNT(*) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
            ->groupBy('model')
            ->get()
            ->keyBy('model')
            ->toArray();

        // By user (top 10)
        $byUser = (clone $query)
            ->selectRaw('user_id, COUNT(*) as requests, SUM(input_tokens) as input_tokens')
            ->groupBy('user_id')
            ->orderByDesc('requests')
            ->limit(10)
            ->get()
            ->toArray();

        return [
            'total_requests'      => $totalRequests,
            'total_input_tokens'  => (int) $totalInputTokens,
            'total_output_tokens' => (int) $totalOutputTokens,
            'avg_latency_ms'      => (int) round((float) $avgLatency),
            'errors'              => $errorCount,
            'period'              => $period,
            'since'               => $since->toIso8601String(),
            'by_model'            => $byModel,
            'by_user'             => $byUser,
        ];
    }

    private function parsePeriod(string $period): Carbon
    {
        if (preg_match('/^(\d+)d$/', $period, $m)) {
            return Carbon::now()->subDays((int) $m[1]);
        }
        if (preg_match('/^(\d+)h$/', $period, $m)) {
            return Carbon::now()->subHours((int) $m[1]);
        }
        // Default: 24 hours
        return Carbon::now()->subHours(24);
    }
}
