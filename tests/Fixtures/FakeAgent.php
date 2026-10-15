<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Agents\BaseAgent;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;

class FakeAgent extends BaseAgent
{
    public function __construct(public readonly string $tone = 'plain') {}

    public function requestType(): AiRequestTypeContract
    {
        return FakeRequestType::Analysis;
    }

    public function essentialPrompt(): string
    {
        return 'Essential.';
    }

    public function systemPrompt(): string
    {
        return "System, {$this->tone}.";
    }

    protected function agentState(): array
    {
        return ['tone' => $this->tone];
    }
}
