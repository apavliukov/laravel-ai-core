<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Batches;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Models\AiRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final readonly class AiBatches
{
    /**
     * Where a batch stands. Global scopes are dropped: the batch id is a UUID
     * the server handed out, so a tenant scope protects nothing here, and a
     * worker or a poll may read it outside the tenant's context. Never pass a
     * batch id that came from the browser.
     */
    public function status(string $batchId): BatchStatus
    {
        // Polled every few seconds: the statuses alone until the batch is done.
        $statuses = AiCore::requestModel()::query()
            ->withoutGlobalScopes()
            ->where('batch_id', $batchId)
            ->pluck('status');

        $running = $statuses->isEmpty() || $statuses->contains(
            static fn (ProcessStatus $status): bool => $status->isRunning(),
        );

        if ($running) {
            return new BatchStatus(BatchState::Running, new Collection);
        }

        /** @var Collection<int, AiRequest> $requests */
        $requests = AiCore::requestModel()::query()
            ->withoutGlobalScopes()
            ->where('batch_id', $batchId)
            ->with('model.provider')
            ->get();

        $failed = $requests->first(
            static fn (AiRequest $request): bool => $request->status === ProcessStatus::FAILED && $request->request_type->failsBatch(),
        );

        if ($failed instanceof AiRequest) {
            return new BatchStatus(BatchState::Failed, $requests, (string) $failed->error_message);
        }

        return new BatchStatus(BatchState::Completed, $requests);
    }

    /**
     * Point a batch at the record it turned out to belong to, for work that
     * ran against a subject before its real owner existed.
     */
    public function reattach(string $batchId, Model $requestable): void
    {
        if ($batchId === '') {
            return;
        }

        AiCore::requestModel()::query()
            ->where('batch_id', $batchId)
            ->update([
                'requestable_type' => $requestable->getMorphClass(),
                'requestable_id' => $requestable->getKey(),
            ]);
    }
}
