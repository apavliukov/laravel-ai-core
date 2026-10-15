<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Events;

use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use Illuminate\Database\Eloquent\Model;

/**
 * Fired before a request's ledger row is created. A listener that throws
 * (a quota) stops the request: no row is written and the caller gets the
 * exception.
 */
final readonly class AiRequestDispatching
{
    public function __construct(
        public Model $requestable,
        public AiRequestTypeContract $requestType,
        public AiModelContract $model,
        public ?int $userId,
    ) {}
}
