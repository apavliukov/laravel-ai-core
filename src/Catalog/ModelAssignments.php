<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Catalog;

use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use LogicException;

/**
 * The model a platform admin put a request type on. By default nothing is
 * stored: every type runs on the active model of the first enabled provider
 * in its priority (see ModelResolver::forText()). A project
 * that keeps assignments extends this class, stores them where it likes and
 * binds its subclass. Whether an assignment still qualifies is the resolver's
 * call, so a retired model is returned as stored, not hidden.
 */
class ModelAssignments
{
    public function assigned(AiRequestTypeContract $type): ?string
    {
        return null;
    }

    /** Null or an empty string clears the assignment. */
    public function assign(AiRequestTypeContract $type, ?string $model): void
    {
        throw new LogicException('Model assignments are not stored: bind a subclass of '.self::class.' that stores them.');
    }
}
