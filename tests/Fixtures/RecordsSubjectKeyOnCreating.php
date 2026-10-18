<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Models\AiRequest;

/**
 * Stands in for a host's tenant trait: its own creating hook, which must see
 * the subject key the package already stamped.
 */
trait RecordsSubjectKeyOnCreating
{
    public static mixed $seenOnCreating = 'not run';

    public static function bootRecordsSubjectKeyOnCreating(): void
    {
        static::creating(static function (AiRequest $aiRequest): void {
            self::$seenOnCreating = $aiRequest->getAttribute('custom_prompt');
        });
    }
}
