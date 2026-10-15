<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Catalog;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Contracts\AiProviderContract;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Enums\AiCapability;
use AlexPavliukov\AiCore\Exceptions\AiMisconfiguredException;
use AlexPavliukov\AiCore\Models\AiProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

final readonly class ModelResolver
{
    public function __construct(private Container $container) {}

    /**
     * The model that answers a request type: the model a platform admin
     * assigned to the type, else the active provider's active model. Null when
     * no provider is active, or when the active model cannot do what the type
     * requires (reported).
     *
     * @throws AiMisconfiguredException when the assigned model cannot do what the type requires.
     */
    public function forText(AiRequestTypeContract $requestType, string $context): ?AiModelContract
    {
        $aiProvider = AiCore::providerModel()::query()
            ->active()
            ->with('activeModel')
            ->first();

        if (! $aiProvider instanceof AiProvider) {
            return null;
        }

        $assigned = $this->assignedModel($requestType, $aiProvider, $context);

        if ($assigned instanceof AiModelContract) {
            if (! $this->canAnswer($assigned, $requestType)) {
                throw new AiMisconfiguredException("Model {$assigned->value} is assigned to {$requestType->name} but cannot do what it requires", [
                    'context' => $context,
                    'required' => $requestType->requiredCapabilities(),
                ]);
            }

            return $assigned;
        }

        $providerEnum = $aiProvider->getEnum();
        $modelEnum = $aiProvider->activeModel?->getEnum();

        if (! $providerEnum instanceof AiProviderContract || ! $modelEnum instanceof AiModelContract || ! $this->canAnswer($modelEnum, $requestType)) {
            $errorMessage = $modelEnum instanceof AiModelContract
                ? 'Active model cannot do what the request type requires'
                : 'Incorrect provider or model enum';

            report(new AiMisconfiguredException($errorMessage, [
                'context' => $context,
                'request_type' => $requestType->name,
                'ai_provider' => $aiProvider->id,
                'provider_enum' => $providerEnum,
                'model_enum' => $modelEnum,
            ]));

            return null;
        }

        return $modelEnum;
    }

    /**
     * The model that answers Classifications, or null when classification is
     * not configured here: its provider has no API key, or its row was never
     * seeded. Unlike text, nothing is "activated": the one model that can
     * classify is the one that does.
     */
    public function forClassification(): ?AiModelContract
    {
        // Asked on every render of a card that offers it and again by the action it calls.
        return once(static function (): ?AiModelContract {
            // ponytail: the first classifier wins; choosing a model per capability
            // or request type arrives when a project has two classifiers.
            $model = AiCore::modelsWithCapability(AiCapability::Classification)[0] ?? null;

            if (! $model instanceof AiModelContract || blank(config("ai.providers.{$model->provider()->value}.key"))) {
                return null;
            }

            return AiCore::modelModel()::query()->where('name', $model->value)->exists() ? $model : null;
        });
    }

    /**
     * The model assigned to the request type, while it is still a seeded model
     * of the active provider. An assignment to a retired or foreign model is
     * logged and ignored, so retiring a model never stops generation.
     */
    private function assignedModel(AiRequestTypeContract $requestType, AiProvider $aiProvider, string $context): ?AiModelContract
    {
        $assigned = $this->container->make(ModelAssignments::class)->assigned($requestType);

        if ($assigned === null) {
            return null;
        }

        $model = AiCore::modelEnum()::tryFrom($assigned);

        if ($model instanceof AiModelContract
            && $model->provider() === $aiProvider->getEnum()
            && $aiProvider->loadMissing('models')->models->contains('name', $model->value)) {
            return $model;
        }

        Log::warning('Assigned model is not in the active catalogue; using the active model', [
            'context' => $context,
            'request_type' => $requestType->name,
            'assigned_model' => $assigned,
        ]);

        return null;
    }

    private function canAnswer(AiModelContract $model, AiRequestTypeContract $requestType): bool
    {
        return array_diff(
            array_map(static fn (AiCapability $capability): string => $capability->value, $requestType->requiredCapabilities()),
            array_map(static fn (AiCapability $capability): string => $capability->value, $model->capabilities()),
        ) === [];
    }
}
