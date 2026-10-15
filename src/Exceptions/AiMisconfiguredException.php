<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Exceptions;

use RuntimeException;

/**
 * The catalogue or the config cannot answer a request: no class registered, or
 * the active model cannot do what the request needs.
 */
final class AiMisconfiguredException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
