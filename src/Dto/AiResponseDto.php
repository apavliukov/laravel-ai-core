<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Dto;

use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Models\AiRequest;

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
     * The DTO a ledger row stands for: what the executor returns once it has
     * written the row, and what a poll reads back from a finished one.
     */
    public static function fromRequest(AiRequest $request): self
    {
        $cost = $request->api_cost;

        return new self(
            success: $request->status === ProcessStatus::COMPLETED,
            model: $request->model?->enumOrFallback(),
            text: mb_trim($request->responseText()),
            response: $request->response?->toArray() ?? [],
            usage: $request->tokens?->toArray() ?? [],
            cost: is_numeric($cost) ? (float) $cost : 0.0,
            error: (string) $request->error_message,
            responseTime: $request->response_time ?? 0,
            aiRequestId: $request->id,
            batchId: $request->batch_id,
        );
    }

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
