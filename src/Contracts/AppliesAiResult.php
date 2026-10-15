<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Contracts;

use AlexPavliukov\AiCore\Models\AiRequest;

/**
 * Writes a queued text request's structured answer where it belongs. Runs in
 * the worker before the request is marked completed; throwing fails the
 * request instead.
 */
interface AppliesAiResult
{
    /**
     * @param  array<string, mixed>  $structured  The agent's validated answer.
     * @param  array<string, mixed>  $context  What the dispatching action passed along.
     */
    public function applyResult(AiRequest $aiRequest, array $structured, array $context): void;
}
