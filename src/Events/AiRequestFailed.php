<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Events;

use AlexPavliukov\AiCore\Dto\AiResponseDto;
use AlexPavliukov\AiCore\Models\AiRequest;

final readonly class AiRequestFailed
{
    public function __construct(
        public AiRequest $aiRequest,
        public AiResponseDto $response,
    ) {}
}
