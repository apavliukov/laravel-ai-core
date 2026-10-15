<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Livewire\HasAsyncAiRequest;
use AlexPavliukov\AiCore\Models\AiRequest;
use Illuminate\Database\Eloquent\Collection;

/**
 * A component's polling half, without Livewire.
 */
final class Poller
{
    use HasAsyncAiRequest;

    /** @var Collection<int, AiRequest>|null */
    public ?Collection $completed = null;

    public ?string $failed = null;

    public function __construct(string $batchId)
    {
        $this->startAsyncTracking($batchId);
    }

    protected function onAsyncAiCompleted(Collection $requests): void
    {
        $this->completed = $requests;
    }

    protected function onAsyncAiFailed(string $error): void
    {
        $this->failed = $error;
    }
}
