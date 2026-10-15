<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Feature;

use AlexPavliukov\AiCore\AiRequests;
use AlexPavliukov\AiCore\Dto\AiResponseDto;
use AlexPavliukov\AiCore\Enums\AiCapability;
use AlexPavliukov\AiCore\Execution\AiRequestExecutor;
use AlexPavliukov\AiCore\Models\AiProvider;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeAgent;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeRequestType;
use AlexPavliukov\AiCore\Tests\TestCase;
use Laravel\Ai\Responses\Data\TranscriptionUsage;

final class CapabilityAndResponseTest extends TestCase
{
    public function test_a_capability_resolves_only_on_an_enabled_provider_of_its_priority(): void
    {
        $this->assertNull($this->aiRequests()->modelFor(AiCapability::Transcription));

        $this->seedAiCatalog(FakeModel::Small);

        $this->assertSame(FakeModel::Transcriber, $this->aiRequests()->modelFor(AiCapability::Transcription));
        $this->assertNull($this->aiRequests()->classificationModel());

        $this->seedAiCatalog(FakeModel::Small, FakeModel::Classifier);

        $this->assertSame(FakeModel::Classifier, $this->aiRequests()->classificationModel());
    }

    public function test_text_skips_an_enabled_provider_whose_active_model_cannot_answer_and_never_uses_a_disabled_one(): void
    {
        $this->seedAiCatalog(FakeModel::Classifier, FakeModel::Small);

        $this->assertSame(FakeModel::Small, $this->aiRequests()->textModel(FakeRequestType::Analysis, $this->subject()));

        AiProvider::query()->where('name', 'openai')->update(['is_active' => false]);

        $this->assertNull($this->aiRequests()->textModel(FakeRequestType::Analysis, $this->subject()));
        $this->assertNull($this->aiRequests()->modelFor(AiCapability::Transcription));
    }

    public function test_a_capability_whose_provider_has_no_key_is_not_configured(): void
    {
        config(['ai.providers.openai.key' => '']);
        $this->seedAiCatalog(FakeModel::Small);

        $this->assertNull($this->aiRequests()->modelFor(AiCapability::Transcription));
    }

    public function test_the_executor_returns_the_dto_of_the_row_it_wrote(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        FakeAgent::fake(['  Done.  ']);
        $aiRequest = $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis, 'batch-1');

        $returned = resolve(AiRequestExecutor::class)->execute($aiRequest, new FakeAgent, FakeModel::Small, 'Analyze.');

        $this->assertEquals(AiResponseDto::fromRequest($aiRequest->fresh() ?? $aiRequest), $returned);
        $this->assertTrue($returned->success);
        $this->assertSame('Done.', $returned->text);
        $this->assertSame('batch-1', $returned->batchId);
        $this->assertSame(FakeModel::Small, $returned->model);
    }

    public function test_a_recorded_call_and_a_failed_one_read_back_from_their_rows(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $recorded = $this->aiRequests()->open($this->subject(), FakeModel::Transcriber, FakeRequestType::Check);
        $failed = $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis);

        $this->aiRequests()->record($recorded, FakeModel::Transcriber, 'Hello', [], new TranscriptionUsage(audioSeconds: 60.0), microtime(true));
        resolve(AiRequestExecutor::class)->fail($failed, 'Nope.', null);

        $fromRecorded = AiResponseDto::fromRequest($recorded->refresh());
        $this->assertSame('Hello', $fromRecorded->text);
        $this->assertSame(0.6, $fromRecorded->cost);

        $fromFailed = AiResponseDto::fromRequest($failed->refresh());
        $this->assertFalse($fromFailed->success);
        $this->assertSame('Nope.', $fromFailed->error);
        $this->assertSame(0, $fromFailed->responseTime);
    }

    private function aiRequests(): AiRequests
    {
        return resolve(AiRequests::class);
    }
}
