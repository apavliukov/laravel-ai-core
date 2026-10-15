<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Contracts\AiProviderContract;
use AlexPavliukov\AiCore\Enums\AiCapability;
use Laravel\Ai\Enums\Lab;

enum FakeProvider: string implements AiProviderContract
{
    case OpenAi = 'openai';
    case TypeSafe = 'typesafe';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function lab(): Lab
    {
        return match ($this) {
            self::OpenAi => Lab::OpenAI,
            self::TypeSafe => Lab::TypeSafe,
        };
    }

    public static function priority(AiCapability $capability): array
    {
        return match ($capability) {
            AiCapability::Text, AiCapability::WebSearch => [self::TypeSafe, self::OpenAi],
            AiCapability::Classification => [self::TypeSafe],
            AiCapability::Transcription => [self::OpenAi],
        };
    }

    public function defaultModel(): AiModelContract
    {
        return match ($this) {
            self::OpenAi => FakeModel::Small,
            self::TypeSafe => FakeModel::Classifier,
        };
    }
}
