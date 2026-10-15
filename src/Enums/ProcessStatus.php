<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Enums;

enum ProcessStatus: int
{
    case PENDING = 1;
    case PROCESSING = 2;
    case COMPLETED = 3;
    case FAILED = 4;

    public function label(): string
    {
        return match ($this) {
            self::PENDING => __('Pending'),
            self::PROCESSING => __('Processing'),
            self::COMPLETED => __('Completed'),
            self::FAILED => __('Failed'),
        };
    }

    /** Still waiting for or talking to the provider. */
    public function isRunning(): bool
    {
        return $this === self::PENDING || $this === self::PROCESSING;
    }

    public function isTerminal(): bool
    {
        return ! $this->isRunning();
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'zinc',
            self::PROCESSING => 'blue',
            self::COMPLETED => 'green',
            self::FAILED => 'red',
        };
    }
}
