<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore;

use AlexPavliukov\AiCore\Catalog\ModelResolver;
use AlexPavliukov\AiCore\Console\SyncAiCatalogCommand;
use AlexPavliukov\AiCore\Models\AiModel;
use AlexPavliukov\AiCore\Models\AiProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AiCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-core.php', 'ai-core');

        $this->app->scoped(ModelResolver::class);
    }

    public function boot(): void
    {
        $this->forgetProvidersOnCatalogueChanges();

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

    /**
     * The resolver reads the enabled providers once per request (and per queued
     * job); a provider or a model saved, deleted or restored in that request
     * resets the reading. One wildcard listener, matched by class, so no
     * configured class is read at boot and any host subclass is covered.
     */
    private function forgetProvidersOnCatalogueChanges(): void
    {
        Event::listen(
            ['eloquent.saved: *', 'eloquent.deleted: *', 'eloquent.restored: *'],
            function (string $event, array $payload): void {
                $model = $payload[0] ?? null;

                if (($model instanceof AiProvider || $model instanceof AiModel) && $this->app->resolved(ModelResolver::class)) {
                    $this->app->make(ModelResolver::class)->forgetProviders();
                }
            },
        );
    }
}
