<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Agents;

use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * One AI task the project can run: the instructions it sends and, for the
 * agents that expect JSON back, the shape of the answer.
 *
 * Instructions are always the Essential Prompt (the part the project parses
 * against) followed by the System Prompt (what the product asks of the model),
 * which {@see SystemPrompts} resolves, from code unless the project says
 * otherwise. A member's Custom Prompt is NOT part of the instructions — it
 * travels with the request message.
 *
 * The hooks are protected behind final public methods because Pint's
 * `final_public_method_for_abstract_class` would make a public hook final in a
 * host's abstract agent and break every agent that extends it.
 */
abstract class BaseAgent implements Agent
{
    use Promptable;

    private ?string $composedInstructions = null;

    abstract public function requestType(): AiRequestTypeContract;

    /**
     * The part of the instructions nobody can edit at runtime.
     */
    abstract public function essentialPrompt(): string;

    /**
     * What the product asks of the model for this task, as shipped in code.
     */
    abstract public function systemPrompt(): string;

    /**
     * Memoized: the SDK asks for the instructions again when it builds the
     * request, after the executor has already stored them on the row.
     */
    final public function instructions(): string
    {
        return $this->composedInstructions ??= implode("\n\n", array_filter([
            $this->essentialPrompt(),
            resolve(SystemPrompts::class)->for($this),
            $this->instructionsAddendum(),
        ]));
    }

    /**
     * The state the queue must carry to rebuild this agent in the worker.
     * Every key is a constructor parameter name.
     *
     * @return array<string, mixed>
     */
    final public function state(): array
    {
        return $this->agentState();
    }

    /**
     * Why a structured answer must be rejected, or null when it is acceptable.
     * Runs in the executor next to the truncation guard, so a provider that
     * ignored the contract fails the ledger row instead of completing it.
     *
     * @param  array<string, mixed>  $structured
     */
    final public function rejection(array $structured): ?string
    {
        return $this->validate($structured);
    }

    /**
     * The post-condition an agent puts on its structured answer. Override
     * where the schema alone cannot express the contract.
     *
     * @param  array<string, mixed>  $structured
     */
    protected function validate(array $structured): ?string
    {
        return null;
    }

    /**
     * What this agent carries through the queue. Override where the agent
     * takes constructor arguments.
     *
     * @return array<string, mixed>
     */
    protected function agentState(): array
    {
        return [];
    }

    /**
     * Text placed after the System Prompt, for a run whose mode has to
     * override something the System Prompt says. Empty by default.
     */
    protected function instructionsAddendum(): string
    {
        return '';
    }
}
