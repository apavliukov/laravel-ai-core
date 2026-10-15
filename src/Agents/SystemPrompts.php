<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Agents;

/**
 * Where an agent's System Prompt comes from. Code by default; a project that
 * keeps some prompts elsewhere extends this class, answers for those request
 * types and leaves the rest to parent::for(), then binds its subclass.
 */
class SystemPrompts
{
    public function for(BaseAgent $agent): string
    {
        return $agent->systemPrompt();
    }
}
