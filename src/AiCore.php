<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore;

use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Contracts\AiProviderContract;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Enums\AiCapability;
use AlexPavliukov\AiCore\Exceptions\AiMisconfiguredException;
use AlexPavliukov\AiCore\Models\AiModel;
use AlexPavliukov\AiCore\Models\AiProvider;
use AlexPavliukov\AiCore\Models\AiRequest;

/**
 * The classes a project registered in config/ai-core.php.
 */
final class AiCore
{
    /** @return class-string<AiProvider> */
    public static function providerModel(): string
    {
        return self::class('models.provider', AiProvider::class);
    }

    /** @return class-string<AiModel> */
    public static function modelModel(): string
    {
        return self::class('models.model', AiModel::class);
    }

    /** @return class-string<AiRequest> */
    public static function requestModel(): string
    {
        return self::class('models.request', AiRequest::class);
    }

    /** @return class-string<AiProviderContract> */
    public static function providerEnum(): string
    {
        return self::class('enums.provider', AiProviderContract::class);
    }

    /** @return class-string<AiModelContract> */
    public static function modelEnum(): string
    {
        return self::class('enums.model', AiModelContract::class);
    }

    /** @return class-string<AiRequestTypeContract> */
    public static function requestTypeEnum(): string
    {
        return self::class('enums.request_type', AiRequestTypeContract::class);
    }

    /**
     * Who acts now: the given user id, else the signed-in user's, else null
     * (a worker, the CLI). Keys stay as they are, so a string key (UUID, ULID)
     * is never mistaken for nobody.
     */
    public static function actor(int|string|null $userId = null): int|string|null
    {
        if ($userId !== null) {
            return $userId;
        }

        $authId = auth()->id();

        return is_int($authId) || is_string($authId) ? $authId : null;
    }

    /**
     * The ledger column stamped from the subject's AiRequestable::aiSubjectKey(),
     * or null when the host stamps nothing.
     */
    public static function subjectKeyColumn(): ?string
    {
        $column = config('ai-core.subject_key.column');

        return is_string($column) && $column !== '' ? $column : null;
    }

    /**
     * @param  'providers'|'models'|'requests'  $table
     */
    public static function table(string $table): string
    {
        $name = config("ai-core.table_names.$table");

        return is_string($name) && $name !== '' ? $name : "ai_$table";
    }

    /**
     * @return list<AiModelContract>
     */
    public static function modelsWithCapability(AiCapability $capability): array
    {
        return array_values(array_filter(
            self::modelEnum()::cases(),
            static fn (AiModelContract $model): bool => in_array($capability, $model->capabilities(), true),
        ));
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $parent
     * @return class-string<T>
     */
    private static function class(string $key, string $parent): string
    {
        $class = config("ai-core.$key");

        if (! is_string($class) || ! is_a($class, $parent, true)) {
            throw new AiMisconfiguredException("config('ai-core.$key') must name a class implementing $parent.");
        }

        return $class;
    }
}
