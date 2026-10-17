<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Catalog;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Enums\AiCapability;
use AlexPavliukov\AiCore\Exceptions\AiMisconfiguredException;
use AlexPavliukov\AiCore\Models\AiProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

final readonly class ModelResolver
{
    public function __construct(private Container $container) {}

    /**
     * The model that answers a request type: the model a platform admin
     * assigned to the type, else the active model of the first enabled
     * provider, in the priority of the type's most specific capability, whose
     * active model can do what the type requires. Null when no provider is
     * enabled or none can answer; the caller decides whether that is an error
     * (AiRequests throws). A disabled provider is never used.
     *
     * @throws AiMisconfiguredException when the assigned model cannot do what the type requires.
     */
    public function forText(AiRequestTypeContract $requestType, string $context): ?AiModelContract
    {
        $enabled = $this->enabledProviders();

        if ($enabled->isEmpty()) {
            return null;
        }

        $assigned = $this->assignedModel($requestType, $enabled, $context);

        if ($assigned instanceof AiModelContract) {
            if (! $this->canAnswer($assigned, $requestType)) {
                throw new AiMisconfiguredException("Model {$assigned->value} is assigned to {$requestType->name} but cannot do what it requires", [
                    'context' => $context,
                    'required' => $requestType->requiredCapabilities(),
                ]);
            }

            return $assigned;
        }

        foreach (AiCore::providerEnum()::priority($this->priorityCapability($requestType)) as $provider) {
            $model = $enabled->get($provider->value)?->activeModel?->getEnum();

            if ($model instanceof AiModelContract && $this->canAnswer($model, $requestType)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * The model for requests needing one capability (a transcription, a
     * classification): the first catalogued model with it, of the first
     * enabled provider in that capability's priority whose API key is set.
     * Null when none qualifies. A disabled provider is never used.
     */
    public function forCapability(AiCapability $capability): ?AiModelContract
    {
        // Asked on every render of a card that offers it and again by the action it calls.
        return once(function () use ($capability): ?AiModelContract {
            $enabled = $this->enabledProviders();

            foreach (AiCore::providerEnum()::priority($capability) as $provider) {
                $row = $enabled->get($provider->value);

                if (! $row instanceof AiProvider || blank(config("ai.providers.{$provider->value}.key"))) {
                    continue;
                }

                $seeded = $row->models->pluck('name')->all();
                $model = array_find(
                    AiCore::modelsWithCapability($capability),
                    static fn (AiModelContract $model): bool => $model->provider() === $provider && in_array($model->value, $seeded, true),
                );

                if ($model instanceof AiModelContract) {
                    return $model;
                }
            }

            return null;
        });
    }

    /**
     * The models a request type may be assigned: the catalogued models of the
     * enabled providers that can do everything the type requires. What a
     * settings page offers, and what ModelAssignments::assign() accepts.
     *
     * @return list<AiModelContract>
     */
    public function assignableModels(AiRequestTypeContract $requestType): array
    {
        $enabled = $this->enabledProviders();

        return array_values(array_filter(
            AiCore::modelEnum()::cases(),
            fn (AiModelContract $model): bool => $this->canAnswer($model, $requestType)
                && (bool) $enabled->get($model->provider()->value)?->models->contains('name', $model->value),
        ));
    }

    public function forClassification(): ?AiModelContract
    {
        return $this->forCapability(AiCapability::Classification);
    }

    /**
     * @return Collection<array-key, AiProvider>
     */
    private function enabledProviders(): Collection
    {
        /** @var Collection<array-key, AiProvider> */
        return AiCore::providerModel()::query()
            ->active()
            ->with(['activeModel', 'models'])
            ->get()
            ->keyBy('name');
    }

    /**
     * The model assigned to the request type, while it is a seeded model of an
     * enabled provider. An assignment to a retired model or to a disabled
     * provider is logged and ignored, so neither stops generation.
     *
     * @param  Collection<array-key, AiProvider>  $enabled
     */
    private function assignedModel(AiRequestTypeContract $requestType, Collection $enabled, string $context): ?AiModelContract
    {
        $assigned = $this->container->make(ModelAssignments::class)->assigned($requestType);

        if ($assigned === null) {
            return null;
        }

        $model = AiCore::modelEnum()::tryFrom($assigned);
        $provider = $model instanceof AiModelContract ? $enabled->get($model->provider()->value) : null;

        if ($model instanceof AiModelContract && $provider instanceof AiProvider && $provider->models->contains('name', $model->value)) {
            return $model;
        }

        Log::warning('Assigned model is not in the catalogue of an enabled provider; using the priority', [
            'context' => $context,
            'request_type' => $requestType->name,
            'assigned_model' => $assigned,
        ]);

        return null;
    }

    /**
     * The capability whose priority a request type walks: its most specific
     * one (web search over plain text), so a type that searches tries the
     * providers ranked for searching.
     */
    private function priorityCapability(AiRequestTypeContract $requestType): AiCapability
    {
        $specific = array_filter(
            $requestType->requiredCapabilities(),
            static fn (AiCapability $capability): bool => $capability !== AiCapability::Text,
        );

        return reset($specific) ?: AiCapability::Text;
    }

    private function canAnswer(AiModelContract $model, AiRequestTypeContract $requestType): bool
    {
        return array_diff(
            array_map(static fn (AiCapability $capability): string => $capability->value, $requestType->requiredCapabilities()),
            array_map(static fn (AiCapability $capability): string => $capability->value, $model->capabilities()),
        ) === [];
    }
}
