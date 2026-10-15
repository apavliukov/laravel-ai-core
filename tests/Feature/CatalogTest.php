<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Feature;

use AlexPavliukov\AiCore\Catalog\ModelAssignments;
use AlexPavliukov\AiCore\Catalog\ModelResolver;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Exceptions\AiMisconfiguredException;
use AlexPavliukov\AiCore\Models\AiModel;
use AlexPavliukov\AiCore\Models\AiProvider;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeRequestType;
use AlexPavliukov\AiCore\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

final class CatalogTest extends TestCase
{
    public function test_the_sync_mirrors_the_enums_and_replaces_a_retired_active_model(): void
    {
        $retired = AiModel::query()->create(['ai_provider_id' => AiProvider::query()->create(['name' => 'openai', 'display_name' => 'x'])->id, 'name' => 'gone', 'display_name' => 'Gone']);
        AiProvider::query()->where('name', 'openai')->update(['is_active' => true, 'active_model_id' => $retired->id]);

        $this->assertSame(0, Artisan::call('ai-core:sync'));

        $this->assertSame(['classifier', 'large', 'small', 'transcriber'], AiModel::query()->orderBy('name')->pluck('name')->all());
        $this->assertSoftDeleted($retired);

        $openAi = AiProvider::query()->where('name', 'openai')->sole();
        $this->assertTrue($openAi->is_active);
        $this->assertSame(FakeModel::Small, $openAi->activeModel?->getEnum());
    }

    public function test_a_request_type_runs_on_the_active_model_without_assignments(): void
    {
        $this->seedAiCatalog(FakeModel::Small);

        $this->assertSame(FakeModel::Small, $this->resolver()->forText(FakeRequestType::Analysis, 'test'));
    }

    public function test_a_bound_assignment_wins_while_it_still_qualifies(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $assignments = new class extends ModelAssignments
        {
            public ?string $model = null;

            public function assigned(AiRequestTypeContract $type): ?string
            {
                return $this->model;
            }

            public function assign(AiRequestTypeContract $type, ?string $model): void
            {
                $this->model = $model;
            }
        };
        app()->instance(ModelAssignments::class, $assignments);

        $assignments->assign(FakeRequestType::Analysis, FakeModel::Large->value);
        $this->assertSame(FakeModel::Large, $this->resolver()->forText(FakeRequestType::Analysis, 'test'));

        $assignments->assign(FakeRequestType::Analysis, 'retired-model');
        $this->assertSame(FakeModel::Small, $this->resolver()->forText(FakeRequestType::Analysis, 'test'));

        $assignments->assign(FakeRequestType::Analysis, FakeModel::Transcriber->value);
        $this->expectException(AiMisconfiguredException::class);
        $this->resolver()->forText(FakeRequestType::Analysis, 'test');
    }

    public function test_nothing_answers_text_without_an_active_provider_or_with_a_non_text_model(): void
    {
        $this->assertNull($this->resolver()->forText(FakeRequestType::Analysis, 'test'));

        $this->seedAiCatalog(FakeModel::Transcriber);

        $this->assertNull($this->resolver()->forText(FakeRequestType::Analysis, 'test'));
    }

    public function test_the_classifier_needs_its_row_its_enabled_provider_and_its_key(): void
    {
        $this->assertNull($this->resolver()->forClassification());

        $this->seedAiCatalog(FakeModel::Small);
        $this->assertNull($this->resolver()->forClassification());

        $this->seedAiCatalog(FakeModel::Small, FakeModel::Classifier);
        $this->assertSame(FakeModel::Classifier, $this->resolver()->forClassification());

        config(['ai.providers.typesafe.key' => '']);
        $this->assertNull($this->resolver()->forClassification());
    }

    private function resolver(): ModelResolver
    {
        return resolve(ModelResolver::class);
    }
}
