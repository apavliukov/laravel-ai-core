<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Database\Factories\AiRequestFactory;

final class HostAiRequestFactory extends AiRequestFactory
{
    protected $model = HostAiRequest::class;
}
