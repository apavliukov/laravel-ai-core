<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Contracts;

/**
 * A subject an AI request can be about, when the host stamps a key of the
 * subject on every ledger row (`ai-core.subject_key.column`). A host's own
 * interface for the same subjects (admin URL, label) may extend this one.
 */
interface AiRequestable
{
    /**
     * The value stamped into the configured column of the request's row.
     */
    public function aiSubjectKey(): int|string;
}
