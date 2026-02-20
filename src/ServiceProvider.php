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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneUsageLogs::class]);
        }

        $this->registerInternalRoutes();
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
    }
}
