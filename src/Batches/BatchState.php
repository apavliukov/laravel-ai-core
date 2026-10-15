<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Batches;

enum BatchState
{
    case Running;
    case Completed;
    case Failed;
}
