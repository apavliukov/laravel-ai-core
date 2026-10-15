<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Override;

/**
 * Hands the executor a response built by hand: the SDK's fake drops provider
 * tool calls and always reports a finished answer.
 */
final class CannedAgent extends FakeAgent
{
    public function __construct(private readonly AgentResponse $canned)
    {
        parent::__construct();
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @param  Lab|array<int, mixed>|string|null  $provider
     */
    #[Override]
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        return $this->canned;
    }
}
