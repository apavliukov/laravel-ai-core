<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Enums;

use AlexPavliukov\AiCore\Contracts\AiModelContract;

/**
 * What kind of request a model can answer. A model declares its capabilities
 * in {@see AiModelContract::capabilities()}; a request only ever reaches a
 * model that declares the capability it needs.
 */
enum AiCapability: string
{
    /** Instructions in, text or structured JSON out: every Agent. */
    case Text = 'text';

    /** Audio in, text out. */
    case Transcription = 'transcription';

    /** State and typed questions in, probabilities out: a Classification. */
    case Classification = 'classification';

    /** The provider's web search tool, on top of Text. */
    case WebSearch = 'web_search';
}
