<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Agents;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use InvalidArgumentException;

/**
 * Resolves the Agent behind a request type. A settings page reads the
 * registry to list the text Agents; the queue worker reads it to rebuild the
 * Agent it must run.
 */
final readonly class AgentRegistry
{
    /**
     * Request types that are answered by a text Agent, in enum order.
     *
     * @return list<AiRequestTypeContract>
     */
    public function registeredTypes(): array
    {
        return array_values(array_filter(
            AiCore::requestTypeEnum()::cases(),
            static fn (AiRequestTypeContract $type): bool => $type->agentClass() !== null,
        ));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function make(AiRequestTypeContract $type, array $state = []): BaseAgent
    {
        $class = $type->agentClass();

        if ($class === null) {
            throw new InvalidArgumentException("No agent is registered for request type: {$type->name}");
        }

        // Every state key is a constructor parameter name, so the state spreads
        // straight into the agent as named arguments.
        return new $class(...$state);
    }
}
