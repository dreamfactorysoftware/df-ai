<?php

namespace DreamFactory\Core\AI;

use DreamFactory\Core\AI\Commands\PruneUsageLogs;
use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Providers\AiProviderFactory;
use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai.php', 'df-ai');

        $this->app->resolving('df.service', function (ServiceManager $df) {
            $df->addType(
                new ServiceType([
                    'name'            => 'ai_connection',
                    'label'           => 'AI Connection',
                    'description'     => 'Connect to AI/LLM providers (Anthropic, OpenAI, xAI, Ollama, custom OpenAI-compatible endpoints).',
                    'group'           => ServiceTypeGroups::AI,
                    'config_handler'  => AiConnectionConfig::class,
                    'factory'         => function ($config) {
                        return new AiConnection($config);
                    },
                ])
            );
        });

        // Register internal routes during booting (before normal boot()) so
        // they take priority over df-file's greedy {storage}/{path} catch-all
        // which has an empty prefix and swallows all 2-segment GETs. Same
        // pattern as df-ai-guardian's approval routes.
        $this->app->booting(function (): void {
            $this->registerInternalRoutes();
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneUsageLogs::class]);
        }
    }

    /**
     * Register internal API routes for admin operations that don't
     * go through the normal DreamFactory service routing (e.g. testing
     * a provider connection before a service is saved).
     */
    private function registerInternalRoutes(): void
    {
        Route::middleware('df.auth_check')->group(function () {
            Route::post('_internal/ai/test-connection', function (Request $request) {
                if (!Session::isSysAdmin()) {
                    return response()->json(
                        ['error' => ['message' => 'Admin access required.']],
                        403
                    );
                }

                $config = $request->only([
                    'provider', 'api_key', 'base_url', 'organization_id',
                    'extra_headers', 'timeout',
                ]);

                if (empty($config['provider'])) {
                    return response()->json(
                        ['error' => ['message' => 'Provider is required.']],
                        422
                    );
                }

                try {
                    $provider = AiProviderFactory::make($config);
                    $models = $provider->listModels();

                    return response()->json([
                        'success'  => true,
                        'provider' => $config['provider'],
                        'resource' => $models,
                    ]);
                } catch (\Throwable $e) {
                    return response()->json([
                        'success' => false,
                        'error'   => ['message' => $e->getMessage()],
                    ], 400);
                }
            });

        });

        // Org-wide usage aggregation across all AI Connections — powers the
        // admin "AI Usage Analytics" dashboard.
        Route::middleware('df.auth_check')->get('_internal/ai/usage', function (Request $request) {
                if (!Session::isSysAdmin()) {
                    return response()->json(
                        ['error' => ['message' => 'Admin access required.']],
                        403
                    );
                }

                $period = $request->get('period', '7d');
                $since = self::parsePeriodToCarbon($period);

                $base = \DreamFactory\Core\AI\Models\AiUsageLog::query()
                    ->where('created_at', '>=', $since);

                $totalRequests = (clone $base)->count();
                $totalInput = (clone $base)->sum('input_tokens');
                $totalOutput = (clone $base)->sum('output_tokens');
                $errorCount = (clone $base)->where('status', 'error')->count();
                $avgLatency = (clone $base)->avg('latency_ms');

                $byService = (clone $base)
                    ->selectRaw('service_id, COUNT(*) as requests, '
                        . 'SUM(input_tokens) as input_tokens, '
                        . 'SUM(output_tokens) as output_tokens, '
                        . 'AVG(latency_ms) as avg_latency, '
                        . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as errors', ['error'])
                    ->groupBy('service_id')
                    ->get();

                $byUser = (clone $base)
                    ->selectRaw('user_id, COUNT(*) as requests, '
                        . 'SUM(input_tokens) as input_tokens, '
                        . 'SUM(output_tokens) as output_tokens')
                    ->groupBy('user_id')
                    ->orderByDesc('requests')
                    ->get();

                $byRole = (clone $base)
                    ->selectRaw('role_id, COUNT(*) as requests, '
                        . 'SUM(input_tokens) as input_tokens, '
                        . 'SUM(output_tokens) as output_tokens')
                    ->groupBy('role_id')
                    ->orderByDesc('requests')
                    ->get();

                $byProvider = (clone $base)
                    ->selectRaw('provider, COUNT(*) as requests, '
                        . 'SUM(input_tokens) as input_tokens, '
                        . 'SUM(output_tokens) as output_tokens')
                    ->groupBy('provider')
                    ->get();

                $byModel = (clone $base)
                    ->selectRaw('model, provider, COUNT(*) as requests, '
                        . 'SUM(input_tokens) as input_tokens, '
                        . 'SUM(output_tokens) as output_tokens')
                    ->groupBy('model', 'provider')
                    ->orderByDesc('requests')
                    ->get();

                $byResource = (clone $base)
                    ->selectRaw('resource, COUNT(*) as requests')
                    ->groupBy('resource')
                    ->get();

                // Daily time series (date_format works on both MySQL and SQLite).
                $driver = \DB::connection()->getDriverName();
                $dateExpr = $driver === 'sqlite'
                    ? "strftime('%Y-%m-%d', created_at)"
                    : "DATE_FORMAT(created_at, '%Y-%m-%d')";
                $series = (clone $base)
                    ->selectRaw("$dateExpr as date, COUNT(*) as requests, "
                        . 'SUM(input_tokens) as input_tokens, '
                        . 'SUM(output_tokens) as output_tokens, '
                        . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as errors', ['error'])
                    ->groupBy(\DB::raw($dateExpr))
                    ->orderBy(\DB::raw($dateExpr))
                    ->get();

                return response()->json([
                    'period'              => $period,
                    'since'               => $since->toIso8601String(),
                    'total_requests'      => $totalRequests,
                    'total_input_tokens'  => (int) $totalInput,
                    'total_output_tokens' => (int) $totalOutput,
                    'errors'              => $errorCount,
                    'avg_latency_ms'      => (int) round((float) $avgLatency),
                    'by_service'          => $byService,
                    'by_user'             => $byUser,
                    'by_role'             => $byRole,
                    'by_provider'         => $byProvider,
                    'by_model'            => $byModel,
                    'by_resource'         => $byResource,
                    'series'              => $series,
                ]);
        });
    }

    private static function parsePeriodToCarbon(string $period): \Illuminate\Support\Carbon
    {
        if (preg_match('/^(\d+)d$/', $period, $m)) {
            return \Illuminate\Support\Carbon::now()->subDays((int) $m[1]);
        }
        if (preg_match('/^(\d+)h$/', $period, $m)) {
            return \Illuminate\Support\Carbon::now()->subHours((int) $m[1]);
        }
        return \Illuminate\Support\Carbon::now()->subDays(7);
    }
}
