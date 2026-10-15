<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Livewire;

use AlexPavliukov\AiCore\Batches\AiBatches;
use AlexPavliukov\AiCore\Batches\BatchState;
use AlexPavliukov\AiCore\Dto\AiResponseDto;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Models\AiRequest;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;

/**
 * Polls a queued batch from a Livewire component. The component starts
 * tracking the batch id its action returned, calls pollAsyncAiRequest() from
 * `wire:poll`, and decides itself what a completion or a failure shows.
 */
trait HasAsyncAiRequest
{
    /**
     * Set only by the server: polling reads the batch across tenants, so a
     * batch id from the browser would read another tenant's answers.
     */
    #[Locked]
    public ?string $asyncBatchId = null;

    #[Locked]
    public bool $asyncInProgress = false;

    /**
     * @param  Collection<int, AiRequest>  $requests
     */
    abstract protected function onAsyncAiCompleted(Collection $requests): void;

    abstract protected function onAsyncAiFailed(string $error): void;

    public function pollAsyncAiRequest(): void
    {
        if (! $this->asyncInProgress || $this->asyncBatchId === null) {
            return;
        }

        $status = resolve(AiBatches::class)->status($this->asyncBatchId);

        if ($status->state === BatchState::Running) {
            return;
        }

        $this->asyncInProgress = false;

        if ($status->state === BatchState::Failed) {
            $this->onAsyncAiFailed($status->error);

            return;
        }

        $this->onAsyncAiCompleted($status->requests);
    }

    /**
     * True, after telling the member, when a batch is still running.
     */
    protected function guardAsyncBusy(): bool
    {
        if (! $this->asyncInProgress) {
            return false;
        }

        $this->onAsyncAiBusy();

        return true;
    }

    /**
     * What the member is shown when they start another request while one runs.
     */
    protected function onAsyncAiBusy(): void {}

    protected function startAsyncTracking(string $batchId): void
    {
        $this->asyncBatchId = $batchId;
        $this->asyncInProgress = true;
    }

    protected function stopAsyncTracking(): void
    {
        $this->asyncInProgress = false;
        $this->asyncBatchId = null;
    }

    protected function aiResponseFor(AiRequest $request): AiResponseDto
    {
        return new AiResponseDto(
            success: $request->status === ProcessStatus::COMPLETED,
            model: $request->model?->enumOrFallback(),
            text: $request->responseText(),
            response: $request->response?->toArray() ?? [],
            error: (string) $request->error_message,
            aiRequestId: $request->id,
            batchId: $request->batch_id,
        );
    }
}
