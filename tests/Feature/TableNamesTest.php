<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Feature;

use AlexPavliukov\AiCore\AiRequests;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeRequestType;
use AlexPavliukov\AiCore\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

final class TableNamesTest extends TestCase
{
    public function test_the_catalogue_and_the_ledger_live_in_the_configured_tables(): void
    {
        $this->seedAiCatalog(FakeModel::Small);

        resolve(AiRequests::class)->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis);

        $this->assertFalse(Schema::hasTable('ai_requests'));
        $this->assertDatabaseCount('llm_providers', 2);
        $this->assertDatabaseCount('llm_models', 4);
        $this->assertDatabaseCount('llm_requests', 1);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ai-core.table_names', [
            'providers' => 'llm_providers',
            'models' => 'llm_models',
            'requests' => 'llm_requests',
        ]);
    }
}
