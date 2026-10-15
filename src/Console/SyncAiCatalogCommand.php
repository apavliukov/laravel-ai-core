<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Console;

use AlexPavliukov\AiCore\Catalog\SyncAiCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai-core:sync')]
#[Description('Sync AI providers and models from the project enums into the database')]
final class SyncAiCatalogCommand extends Command
{
    public function handle(SyncAiCatalog $sync): int
    {
        $sync();

        $this->components->info('AI catalogue synced.');

        return self::SUCCESS;
    }
}
