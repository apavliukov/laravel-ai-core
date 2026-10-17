<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Exceptions;

use RuntimeException;

/**
 * An answer the executor refused: cut off by the token limit, or rejected by
 * the agent's validate(). Reported so a model or a prompt that keeps failing
 * this way leaves a trace; a host that finds it noisy can throttle or ignore
 * this one class.
 */
final class AiRequestRejectedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $reason, private readonly array $context)
    {
        parent::__construct("AI answer rejected: {$reason}");
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
