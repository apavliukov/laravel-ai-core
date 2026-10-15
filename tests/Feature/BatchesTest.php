<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Feature;

use AlexPavliukov\AiCore\AiRequests;
use AlexPavliukov\AiCore\Batches\AiBatches;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Models\AiRequest;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeRequestType;
use AlexPavliukov\AiCore\Tests\Fixtures\Poller;
use AlexPavliukov\AiCore\Tests\TestCase;

final class BatchesTest extends TestCase
{
    public function test_a_poll_waits_for_the_whole_batch_and_fails_only_on_a_type_that_fails_it(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $analysis = $this->open(FakeRequestType::Analysis, 'batch-1');
        $check = $this->open(FakeRequestType::Check, 'batch-1');
        $poller = new Poller('batch-1');

        $analysis->update(['status' => ProcessStatus::COMPLETED]);
        $poller->pollAsyncAiRequest();
        $this->assertTrue($poller->asyncInProgress);

        $check->update(['status' => ProcessStatus::FAILED, 'error_message' => 'Classifier down']);
        $poller->pollAsyncAiRequest();
        $this->assertFalse($poller->asyncInProgress);
        $this->assertSame(2, $poller->completed?->count());

        $analysis->update(['status' => ProcessStatus::FAILED, 'error_message' => 'Provider down']);
        $poller = new Poller('batch-1');
        $poller->pollAsyncAiRequest();
        $this->assertSame('Provider down', $poller->failed);
    }

    public function test_a_batch_is_reattached_to_the_subject_it_turned_out_to_belong_to(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $this->open(FakeRequestType::Analysis, 'batch-2');
        $owner = $this->subject();

        resolve(AiBatches::class)->reattach('batch-2', $owner);

        $this->assertSame($owner->getKey(), AiRequest::query()->sole()->requestable_id);
    }

    private function open(FakeRequestType $type, string $batchId): AiRequest
    {
        return resolve(AiRequests::class)->open($this->subject(), FakeModel::Small, $type, $batchId);
    }
}
