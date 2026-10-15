<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Dto;

abstract readonly class BaseAiResultDto
{
    public function __construct(
        public bool $success,
        public string $error = '',
        public ?AiResponseDto $aiResponse = null,
    ) {}

    abstract public static function fromAiResponse(AiResponseDto $aiResponse): self;

    abstract public static function failed(string $error, ?AiResponseDto $aiResponse = null): self;
}
