<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Contracts;

use AlexPavliukov\AiCore\Enums\AiCapability;
use BackedEnum;

/**
 * A model of the project's catalogue. Its value is the model's row name and
 * the id the provider knows it by.
 */
interface AiModelContract extends BackedEnum
{
    public function label(): string;

    /**
     * Rates in dollars per 1M tokens. `cached` is the rate for input tokens read
     * from a prompt cache, `cache_write` for those written to it (the input rate
     * when absent). `web_search` prices 1000 web search calls. A transcription
     * costs its tokens at the token rates plus its audio at `audio_minute` per
     * minute: a model billed by the minute only carries zero token rates.
     *
     * @return array{input: float, cached: float, output: float, cache_write?: float, audio_minute?: float, web_search?: float}
     */
    public function pricing(): array;

    /**
     * @return array<int, AiCapability>
     */
    public function capabilities(): array;

    public function provider(): AiProviderContract;
}
