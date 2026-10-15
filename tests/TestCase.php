<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests;

use AlexPavliukov\AiCore\AiCoreServiceProvider;
use AlexPavliukov\AiCore\Testing\InteractsWithAiCore;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeProvider;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeRequestType;
use AlexPavliukov\AiCore\Tests\Fixtures\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use InteractsWithAiCore;
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, AiCoreServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('ai-core.enums', [
            'provider' => FakeProvider::class,
            'model' => FakeModel::class,
            'request_type' => FakeRequestType::class,
        ]);
        $app['config']->set('ai.providers.typesafe.key', 'test-key');
        $app['config']->set('ai.providers.openai.key', 'test-key');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([__DIR__.'/../database/migrations', __DIR__.'/Fixtures/migrations']);
    }

    protected function subject(): Subject
    {
        return Subject::query()->create();
    }
}
