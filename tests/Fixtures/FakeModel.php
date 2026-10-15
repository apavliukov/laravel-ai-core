<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Contracts\AiProviderContract;
use AlexPavliukov\AiCore\Enums\AiCapability;

enum FakeModel: string implements AiModelContract
{
    case Small = 'small';
    case Large = 'large';
    case Transcriber = 'transcriber';
    case Classifier = 'classifier';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function pricing(): array
    {
        return match ($this) {
            self::Small => ['input' => 1.0, 'cached' => 0.1, 'output' => 2.0, 'web_search' => 10.0],
            self::Large => ['input' => 10.0, 'cached' => 1.0, 'output' => 20.0],
            self::Transcriber => ['input' => 0.0, 'cached' => 0.0, 'output' => 0.0, 'audio_minute' => 0.6],
            self::Classifier => ['input' => 0.5, 'cached' => 0.5, 'output' => 0.0],
        };
    }

    public function capabilities(): array
    {
        return match ($this) {
            self::Small => [AiCapability::Text, AiCapability::WebSearch],
            self::Large => [AiCapability::Text],
            self::Transcriber => [AiCapability::Transcription],
            self::Classifier => [AiCapability::Classification],
        };
    }

    public function provider(): AiProviderContract
    {
        return $this === self::Classifier ? FakeProvider::TypeSafe : FakeProvider::OpenAi;
    }
}
