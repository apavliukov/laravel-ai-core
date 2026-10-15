<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Models;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $ai_provider_id
 * @property string $name
 * @property string $display_name
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'ai_provider_id',
    'name',
    'display_name',
])]
class AiModel extends Model
{
    use SoftDeletes;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = AiCore::table('models');
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        /** @var BelongsTo<AiProvider, $this> */
        return $this->belongsTo(AiCore::providerModel(), 'ai_provider_id')->withTrashed();
    }

    public function getEnum(): ?AiModelContract
    {
        return AiCore::modelEnum()::tryFrom($this->name);
    }

    /**
     * The model a request on this row runs and is priced as: its own case, or,
     * once that case left the enum, its provider's default.
     */
    public function enumOrFallback(): ?AiModelContract
    {
        return $this->getEnum() ?? $this->provider?->getEnum()?->defaultModel();
    }
}
