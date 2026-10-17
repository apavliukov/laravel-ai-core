<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Fixtures;

use AlexPavliukov\AiCore\Contracts\AiRequestable;
use Illuminate\Database\Eloquent\Model;

final class OwnedSubject extends Model implements AiRequestable
{
    protected $table = 'subjects';

    public function aiSubjectKey(): string
    {
        return 'owner-42';
    }
}
