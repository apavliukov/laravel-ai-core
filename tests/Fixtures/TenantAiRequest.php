<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Models\AiRequest;

final class TenantAiRequest extends AiRequest
{
    use RecordsSubjectKeyOnCreating;
}
