<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore;

use AlexPavliukov\AiCore\Console\SyncAiCatalogCommand;
use Illuminate\Support\ServiceProvider;

final class AiCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-core.php', 'ai-core');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([SyncAiCatalogCommand::class]);

        $this->publishes([
            __DIR__.'/../config/ai-core.php' => config_path('ai-core.php'),
        ], 'ai-core-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'ai-core-migrations');
    }
}
