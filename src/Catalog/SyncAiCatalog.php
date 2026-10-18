<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Catalog;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Contracts\AiProviderContract;

/**
 * Syncs providers and models from their enums, idempotently. Run on every
 * deploy: a new enum case appears in the database without a migration, a
 * removed one is soft-deleted. Activation (`is_active`, the provider's active
 * model) is a platform-admin decision and is not touched here, with one
 * exception: an active model that was removed from the catalogue is replaced
 * by the provider's default, so retiring a model never leaves the platform
 * without one.
 */
final readonly class SyncAiCatalog
{
    public function __invoke(): void
    {
        $this->syncProviders();
        $this->syncModels();
        $this->replaceRetiredActiveModels();

        resolve(ModelResolver::class)->forgetProviders();
    }

    private function syncProviders(): void
    {
        $providerClass = AiCore::providerModel();
        $cases = AiCore::providerEnum()::cases();

        foreach ($cases as $providerEnum) {
            $provider = $providerClass::withTrashed()->firstOrNew(
                ['name' => $providerEnum->value],
                ['is_active' => false],
            );

            $provider->display_name = $providerEnum->label();
            $provider->deleted_at = null;

            $provider->save();
        }

        $providerClass::query()
            ->whereNotIn('name', array_map(static fn (AiProviderContract $provider): string|int => $provider->value, $cases))
            ->delete();
    }

    private function syncModels(): void
    {
        $modelClass = AiCore::modelModel();
        $cases = AiCore::modelEnum()::cases();
        $providers = AiCore::providerModel()::query()->get(['id', 'name'])->keyBy('name');

        foreach ($cases as $modelEnum) {
            $providerId = $providers->get($modelEnum->provider()->value)?->id;

            if ($providerId === null) {
                continue;
            }

            $model = $modelClass::withTrashed()->firstOrNew(['name' => $modelEnum->value]);

            $model->ai_provider_id = $providerId;
            $model->display_name = $modelEnum->label();
            $model->deleted_at = null;

            $model->save();
        }

        $modelClass::query()
            ->whereNotIn('name', array_map(static fn (AiModelContract $model): string|int => $model->value, $cases))
            ->delete();
    }

    private function replaceRetiredActiveModels(): void
    {
        $providers = AiCore::providerModel()::query()->whereNotNull('active_model_id')->with('activeModel')->get();

        foreach ($providers as $provider) {
            if ($provider->activeModel !== null) {
                continue;
            }

            $default = $provider->getEnum()?->defaultModel();

            $provider->update([
                'active_model_id' => $default === null ? null : AiCore::modelModel()::query()->named($default)->value('id'),
            ]);
        }
    }
}
