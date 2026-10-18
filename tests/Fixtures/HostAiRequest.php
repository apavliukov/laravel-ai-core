<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Models\AiRequest;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class HostAiRequest extends AiRequest
{
    /** @use HasFactory<HostAiRequestFactory> */
    use HasFactory;
}
