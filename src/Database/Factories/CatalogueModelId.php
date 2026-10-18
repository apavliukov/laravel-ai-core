<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Database\Factories;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use LogicException;

/**
 * The `ai_model_id` a factory row gets from forModel(): looked up when the row is
 * made, and carrying the model itself, so pricing the row needs no second
 * lookup. A factory rebuilds itself on every state, so the model rides in the
 * attributes, not on the factory. Not memoized: a factory kept across database
 * resets must not write an id from an earlier catalogue.
 *
 * @internal
 */
final readonly class CatalogueModelId
{
    public function __construct(public AiModelContract $model) {}

    public function __invoke(): mixed
    {
        return AiCore::modelModel()::query()->named($this->model)->value('id')
            ?? throw new LogicException("Seed the AI catalogue before building rows on {$this->model->value}.");
    }
}
