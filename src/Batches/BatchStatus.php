<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Batches;

use AlexPavliukov\AiCore\Models\AiRequest;
use Illuminate\Database\Eloquent\Collection;

final readonly class BatchStatus
{
    /**
     * @param  Collection<int, AiRequest>  $requests  Loaded once the batch is done; empty while it runs.
     */
    public function __construct(
        public BatchState $state,
        public Collection $requests,
        public string $error = '',
    ) {}
}
