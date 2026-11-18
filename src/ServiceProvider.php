<?php

namespace DreamFactory\Core\AI;

use DreamFactory\Core\AI\Commands\PrunePromptLogs;
use DreamFactory\Core\AI\Commands\PruneUsageLogs;
use DreamFactory\Core\AI\Http\Controllers\InternalAliasController;
use DreamFactory\Core\AI\Http\Controllers\InternalBudgetController;
use DreamFactory\Core\AI\Http\Controllers\InternalUsageController;
use DreamFactory\Core\AI\Http\Controllers\OpenAiCompatController;
use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\AI\Services\BudgetAccess;
use DreamFactory\Core\Enums\LicenseLevel;
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
                    'subscription_required' => LicenseLevel::SILVER,
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

            // Budget and alias management. Not registered while the switch
            // is off, so the routes answer 404 and the admin UI hides the
            // screens. BudgetAccess decides who may call them when it is on.
            if (BudgetAccess::managementEnabled()) {
                Route::get('_internal/ai/budgets', [InternalBudgetController::class, 'index']);
                Route::post('_internal/ai/budgets', [InternalBudgetController::class, 'store']);
                Route::patch('_internal/ai/budgets/{id}', [InternalBudgetController::class, 'update'])->whereNumber('id');
                Route::delete('_internal/ai/budgets/{id}', [InternalBudgetController::class, 'destroy'])->whereNumber('id');

                Route::get('_internal/ai/aliases', [InternalAliasController::class, 'index']);
                Route::post('_internal/ai/aliases', [InternalAliasController::class, 'store']);
                Route::patch('_internal/ai/aliases/{id}', [InternalAliasController::class, 'update'])->whereNumber('id');
                Route::delete('_internal/ai/aliases/{id}', [InternalAliasController::class, 'destroy'])->whereNumber('id');
            }

            // OpenAI-compatible drop-in endpoint. Customer apps point
            // OPENAI_BASE_URL at /api/v2/_ai/v1 and they're done. Same
            // RBAC + rate limits + audit + prompt-logging + fallback
            // chains as native ChatResource — just routed via
            // model-alias instead of explicit AI Connection name.
            Route::post('api/v2/_ai/v1/chat/completions', [OpenAiCompatController::class, 'chatCompletions']);
            Route::get('api/v2/_ai/v1/models', [OpenAiCompatController::class, 'listModels']);
        });
    }
}
