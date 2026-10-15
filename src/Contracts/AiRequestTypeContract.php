<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Contracts;

use AlexPavliukov\AiCore\Agents\BaseAgent;
use AlexPavliukov\AiCore\Enums\AiCapability;
use BackedEnum;

/**
 * What a request is for. Int-backed: the value is stored in the ledger.
 */
interface AiRequestTypeContract extends BackedEnum
{
    /**
     * The agent that answers this type, or null for a type that is not answered
     * by a text agent (a transcription, a classification).
     *
     * @return class-string<BaseAgent>|null
     */
    public function agentClass(): ?string;

    /**
     * Whether a failure of this type fails the whole batch it belongs to.
     */
    public function failsBatch(): bool;

    /**
     * What a model must be able to do to answer this type: [AiCapability::Text]
     * for a plain agent, plus AiCapability::WebSearch for one that searches.
     *
     * @return list<AiCapability>
     */
    public function requiredCapabilities(): array;
}
