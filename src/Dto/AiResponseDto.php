<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Dto;

use AlexPavliukov\AiCore\Contracts\AiModelContract;

final readonly class AiResponseDto
{
    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public bool $success,
        public ?AiModelContract $model,
        public string $text = '',
        public array $response = [],
        public array $usage = [],
        public float $cost = 0.0,
        public string $error = '',
        public int $responseTime = 0,
        public ?int $aiRequestId = null,
        public ?string $batchId = null,
    ) {}

    /**
     * The answer as data, when the agent asked the provider for a schema.
     *
     * @return array<mixed>|null
     */
    public function structured(): ?array
    {
        $structured = $this->response['structured'] ?? null;

        return is_array($structured) ? $structured : null;
    }
}
