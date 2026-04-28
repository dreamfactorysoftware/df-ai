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
        // admin "AI Usage Analytics" dashboard. Heavy lifting in
        // \DreamFactory\Core\AI\Utility\UsageAggregator (unit-tested).
        Route::middleware('df.auth_check')->get('_internal/ai/usage', function (Request $request) {
            if (!Session::isSysAdmin()) {
                return response()->json(
                    ['error' => ['message' => 'Admin access required.']],
                    403
                );
            }

            $period = $request->get('period', '7d');
            $since = \DreamFactory\Core\AI\Utility\UsageAggregator::parsePeriod($period);
            $driver = \DB::connection()->getDriverName();

            // Each filter key may be sent as repeated query params (?service_id=1&service_id=2)
            // or as a CSV (?provider=anthropic,openai). Normalize both shapes here so the
            // aggregator only deals with arrays.
            $filters = [];
            foreach (\DreamFactory\Core\AI\Utility\UsageAggregator::FILTER_KEYS as $key) {
                if (!$request->has($key)) {
                    continue;
                }
                $raw = $request->get($key);
                $values = is_array($raw)
                    ? $raw
                    : array_filter(array_map('trim', explode(',', (string) $raw)), fn($v) => $v !== '');
                if (!empty($values)) {
                    $filters[$key] = array_values($values);
                }
            }

            $result = \DreamFactory\Core\AI\Utility\UsageAggregator::aggregate($since, $driver, $filters);
            $result['period'] = $period;

            return response()->json($result);
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
