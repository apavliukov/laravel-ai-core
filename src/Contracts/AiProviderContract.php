<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Contracts;

use AlexPavliukov\AiCore\Enums\AiCapability;
use BackedEnum;
use Laravel\Ai\Enums\Lab;

/**
 * A provider of the project's catalogue. Its value is the provider's row name
 * and its key in config/ai.php.
 */
interface AiProviderContract extends BackedEnum
{
    public function label(): string;

    /**
     * The Laravel AI SDK provider this case talks to.
     */
    public function lab(): Lab;

    /**
     * The model a provider falls back to when its active model is retired.
     */
    public function defaultModel(): AiModelContract;

    /**
     * The providers to try, in order, for requests needing a capability. Only
     * enabled providers (`is_active`) are ever tried: a disabled one gets no
     * request, whatever this list says.
     *
     * @return list<AiProviderContract>
     */
    public static function priority(AiCapability $capability): array;
}
