<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Wraps a provider failure for report(): the request it broke, without the
 * prompts, which carry the project's private data.
 */
final class AiRequestFailedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(Throwable $previous, private readonly array $context)
    {
        parent::__construct("AI request failed: {$previous->getMessage()}", 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
