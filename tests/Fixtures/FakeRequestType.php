<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Enums\AiCapability;

enum FakeRequestType: int implements AiRequestTypeContract
{
    case Analysis = 1;
    case Check = 2;
    case Search = 3;

    public function agentClass(): ?string
    {
        return match ($this) {
            self::Analysis => FakeAgent::class,
            self::Search => SearchingAgent::class,
            self::Check => null,
        };
    }

    public function failsBatch(): bool
    {
        return $this !== self::Check;
    }

    public function requiredCapabilities(): array
    {
        return $this === self::Search
            ? [AiCapability::Text, AiCapability::WebSearch]
            : [AiCapability::Text];
    }
}
