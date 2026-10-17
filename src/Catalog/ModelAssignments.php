<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Catalog;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Exceptions\AiMisconfiguredException;
use LogicException;

/**
 * The model a platform admin put a request type on. By default nothing is
 * stored: every type runs on the active model of the first enabled provider
 * in its priority (see ModelResolver::forText()). A host that keeps
 * assignments extends this class, implements assigned() and store() over
 * whatever store it likes, and binds its subclass. Whether a stored
 * assignment still qualifies at run time is the resolver's call, so a retired
 * model is returned as stored, not hidden.
 */
class ModelAssignments
{
    public function assigned(AiRequestTypeContract $type): ?string
    {
        return null;
    }

    /**
     * Assign a model to a request type; null or an empty string clears it. Only
     * a model the type can run on is accepted (ModelResolver::assignableModels()),
     * so a settings page cannot save what the resolver would refuse.
     *
     * @throws AiMisconfiguredException when the model is not assignable to the type.
     */
    final public function assign(AiRequestTypeContract $type, ?string $model): void
    {
        if ($model === null || $model === '') {
            $this->store($type, null);

            return;
        }

        $candidate = AiCore::modelEnum()::tryFrom($model);
        $assignable = resolve(ModelResolver::class)->assignableModels($type);

        if (! $candidate instanceof AiModelContract || ! in_array($candidate, $assignable, true)) {
            throw new AiMisconfiguredException("Model {$model} cannot be assigned to {$type->name}.", [
                'request_type' => $type->name,
                'model' => $model,
            ]);
        }

        $this->store($type, (string) $candidate->value);
    }

    /**
     * Write the assignment; null clears it.
     */
    protected function store(AiRequestTypeContract $type, ?string $model): void
    {
        throw new LogicException('Model assignments are not stored: bind a subclass of '.self::class.' that implements store().');
    }
}
