<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Agents\Concerns\SearchesTheWeb;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use Laravel\Ai\Contracts\HasTools;

final class SearchingAgent extends FakeAgent implements HasTools
{
    use SearchesTheWeb;

    public function requestType(): AiRequestTypeContract
    {
        return FakeRequestType::Search;
    }
}
