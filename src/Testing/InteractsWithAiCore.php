<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Testing;

use AlexPavliukov\AiCore\Agents\AgentRegistry;
use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Catalog\ModelResolver;
use AlexPavliukov\AiCore\Catalog\SyncAiCatalog;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use Closure;

/**
 * For the project's tests: a seeded catalogue with one active model, and the
 * same fake answer for every registered agent.
 */
trait InteractsWithAiCore
{
    /**
     * Sync the catalogue from the enums and enable the given models'
     * providers, each with its model as the active one.
     */
    protected function seedAiCatalog(AiModelContract ...$activeModels): void
    {
        resolve(SyncAiCatalog::class)();

        foreach ($activeModels as $activeModel) {
            AiCore::providerModel()::query()
                ->where('name', $activeModel->provider()->value)
                ->update([
                    'is_active' => true,
                    'active_model_id' => AiCore::modelModel()::query()->named($activeModel)->value('id'),
                ]);
        }

        // A bulk update fires no model events, and a test may fake events anyway.
        resolve(ModelResolver::class)->forgetProviders();
    }

    /**
     * The callers do not know which agent a flow will reach, so every
     * registered agent is given the same answers.
     *
     * @param  Closure|array<int, mixed>  $responses  What Agent::fake() takes.
     */
    protected function fakeAgents(Closure|array $responses): void
    {
        foreach (resolve(AgentRegistry::class)->registeredTypes() as $requestType) {
            $agentClass = $requestType->agentClass();

            if ($agentClass !== null) {
                $agentClass::fake($responses);
            }
        }
    }
}
