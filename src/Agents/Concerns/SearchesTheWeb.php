<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Agents\Concerns;

use AlexPavliukov\AiCore\Enums\AiCapability;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Providers\Tools\WebSearch;

/**
 * Gives an agent the provider's web search tool. The agent implements
 * {@see HasTools}; its request type lists AiCapability::WebSearch among its
 * required capabilities. Whether to search is the model's call, unless the
 * agent forces it with withToolChoice().
 */
trait SearchesTheWeb
{
    /**
     * @return list<WebSearch>
     */
    public function tools(): iterable
    {
        return [$this->webSearch()];
    }

    /**
     * Override to cap the searches or narrow the domains.
     */
    protected function webSearch(): WebSearch
    {
        return new WebSearch;
    }
}
