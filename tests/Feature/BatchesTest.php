<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Feature;

use AlexPavliukov\AiCore\AiRequests;
use AlexPavliukov\AiCore\Batches\AiBatches;
use AlexPavliukov\AiCore\Batches\BatchState;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Models\AiRequest;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeRequestType;
use AlexPavliukov\AiCore\Tests\TestCase;

final class BatchesTest extends TestCase
{
    public function test_a_batch_runs_until_every_row_is_done_and_fails_only_on_a_type_that_fails_it(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $analysis = $this->open(FakeRequestType::Analysis, 'batch-1');
        $check = $this->open(FakeRequestType::Check, 'batch-1');

        $analysis->update(['status' => ProcessStatus::COMPLETED]);
        $this->assertSame(BatchState::Running, $this->batches()->status('batch-1')->state);

        $check->update(['status' => ProcessStatus::FAILED, 'error_message' => 'Classifier down']);
        $status = $this->batches()->status('batch-1');
        $this->assertSame(BatchState::Completed, $status->state);
        $this->assertCount(2, $status->requests);

        $analysis->update(['status' => ProcessStatus::FAILED, 'error_message' => 'Provider down']);
        $status = $this->batches()->status('batch-1');
        $this->assertSame(BatchState::Failed, $status->state);
        $this->assertSame('Provider down', $status->error);
    }

    public function test_a_batch_is_reattached_to_the_subject_it_turned_out_to_belong_to(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $this->open(FakeRequestType::Analysis, 'batch-2');
        $owner = $this->subject();

        $this->batches()->reattach('batch-2', $owner);

        $this->assertSame($owner->getKey(), AiRequest::query()->sole()->requestable_id);
    }

    private function batches(): AiBatches
    {
        return resolve(AiBatches::class);
    }

    private function open(FakeRequestType $type, string $batchId): AiRequest
    {
        return resolve(AiRequests::class)->open($this->subject(), FakeModel::Small, $type, $batchId);
    }
}
