<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Types;

use AlexPavliukov\AiCore\Database\Factories\AiRequestFactory;
use AlexPavliukov\AiCore\Models\AiRequest;
use AlexPavliukov\AiCore\Tests\Fixtures\HostAiRequest;
use AlexPavliukov\AiCore\Tests\Fixtures\HostAiRequestFactory;
use Laravel\Ai\Responses\Data\TextUsage;

use function PHPStan\Testing\assertType;

/**
 * Read by Larastan only: a host's factory declared as
 * `@extends AiRequestFactory<HostAiRequest>` keeps the host model through the
 * package states.
 */
function factoryTypes(): void
{
    assertType(HostAiRequest::class, HostAiRequestFactory::new()->completed('Done.', new TextUsage)->createOne());
    assertType(HostAiRequest::class, HostAiRequestFactory::new()->failed('Nope.')->makeOne());
    assertType(AiRequest::class, AiRequestFactory::new()->createOne());
}
