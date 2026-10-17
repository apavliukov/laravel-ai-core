<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Database\Factories;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Execution\AiCost;
use AlexPavliukov\AiCore\Models\AiRequest;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use LogicException;

/**
 * Ledger rows for seeders and tests, in the shapes the executor writes. It
 * writes rows only: no provider, no events, so quotas and analytics never see
 * a seeded row as a request. Needs no Faker. A host's factory for its own
 * subclass extends this one and adds its columns (subject key, tenant).
 *
 * @extends Factory<AiRequest>
 */
class AiRequestFactory extends Factory
{
    /**
     * The configured request model; a host's factory that names its own model
     * keeps it.
     *
     * @return class-string<AiRequest>
     */
    public function modelName(): string
    {
        return static::class === self::class ? AiCore::requestModel() : parent::modelName();
    }

    /**
     * Pending, on the first catalogued model and the first request type. Name
     * the subject with for($subject, 'requestable').
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_model_id' => static fn (): mixed => AiCore::modelModel()::query()->value('id'),
            'batch_id' => static fn (): string => (string) Str::uuid(),
            'request_type' => AiCore::requestTypeEnum()::cases()[0],
            'status' => ProcessStatus::PENDING,
            'payload' => [],
        ];
    }

    /**
     * On the given catalogue model.
     */
    public function forModel(AiModelContract $model): static
    {
        return $this->state(['ai_model_id' => static fn (): mixed => AiCore::modelModel()::query()->where('name', $model->value)->value('id')]);
    }

    /**
     * A finished agent call: a text answer stored as {text}, a structured one
     * as {structured}, both with meta; usage as the SDK serializes it; the cost
     * from the model's prices.
     *
     * @param  string|array<string, mixed>  $answer
     * @param  array<string, mixed>  $payload  What was sent ({model, agent_state, input}).
     */
    public function completed(string|array $answer, TextUsage $usage, array $payload = [], int $webSearchCalls = 0): static
    {
        return $this->state(function (array $attributes) use ($answer, $usage, $payload, $webSearchCalls): array {
            $model = $this->catalogueModel($attributes);

            return [
                'status' => ProcessStatus::COMPLETED,
                'payload' => $payload,
                'response' => [
                    ...(is_string($answer) ? ['text' => $answer] : ['structured' => $answer]),
                    'meta' => ['provider' => (string) $model->provider()->value, 'model' => (string) $model->value, 'citations' => []],
                ],
                'tokens' => [...$usage->toArray(), 'web_search_calls' => $webSearchCalls],
                'api_cost' => AiCost::of($model, $usage, $webSearchCalls),
                'error_message' => null,
            ];
        });
    }

    /**
     * A finished call made outside an agent: a transcription.
     *
     * @param  array<string, mixed>  $payload
     */
    public function transcribed(string $text, TranscriptionUsage $usage, array $payload = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProcessStatus::COMPLETED,
            'payload' => $payload,
            'response' => ['text' => $text],
            'tokens' => $usage->toArray(),
            'api_cost' => AiCost::of($this->catalogueModel($attributes), $usage),
            'error_message' => null,
        ]);
    }

    public function failed(string $error): static
    {
        return $this->state([
            'status' => ProcessStatus::FAILED,
            'error_message' => $error,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function catalogueModel(array $attributes): AiModelContract
    {
        $id = $attributes['ai_model_id'] ?? null;
        $id = $id instanceof Closure ? $id() : $id;

        return AiCore::modelModel()::query()->whereKey($id)->first()?->enumOrFallback()
            ?? throw new LogicException('Seed the AI catalogue before building priced rows (no model for this row).');
    }
}
