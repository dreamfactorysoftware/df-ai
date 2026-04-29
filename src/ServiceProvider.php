<?php

namespace DreamFactory\Core\AI;

use DreamFactory\Core\AI\Commands\PrunePromptLogs;
use DreamFactory\Core\AI\Commands\PruneUsageLogs;
use DreamFactory\Core\AI\Http\Controllers\InternalUsageController;
use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
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
            $this->commands([PruneUsageLogs::class, PrunePromptLogs::class]);
        }
    }

    /**
     * Internal admin endpoints that don't fit the normal service-routing
     * pattern. Handlers live in InternalUsageController so they can be
     * unit-tested in isolation.
     */
    private function registerInternalRoutes(): void
    {
        Route::middleware('df.auth_check')->group(function () {
            Route::post('_internal/ai/test-connection', [InternalUsageController::class, 'testConnection']);
            Route::get('_internal/ai/usage', [InternalUsageController::class, 'usage']);
            // SIEM pull endpoint — NDJSON stream of ECS-shaped audit
            // events for Logstash http_poller / Splunk HTTP / Datadog
            // HTTP intake / any pull-based SIEM pipeline.
            Route::get('_internal/ai/audit-stream', [InternalUsageController::class, 'auditStream']);
        });
    }
}
