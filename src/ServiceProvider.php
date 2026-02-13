<?php

namespace DreamFactory\Core\AI;

use DreamFactory\Core\AI\Models\AiConnectionConfig;
use DreamFactory\Core\AI\Services\AiConnection;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;

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
    }
}
