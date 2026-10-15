<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Models;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Contracts\AiProviderContract;
use AlexPavliukov\AiCore\Enums\AiCapability;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property bool $is_active
 * @property int|null $active_model_id
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'name',
    'display_name',
    'is_active',
    'active_model_id',
])]
class AiProvider extends Model
{
    use SoftDeletes;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = AiCore::table('providers');
    }

    /**
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiCore::modelModel(), 'ai_provider_id');
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function activeModel(): BelongsTo
    {
        return $this->belongsTo(AiCore::modelModel(), 'active_model_id');
    }

    /**
     * The text models of this provider that are in the catalogue and seeded:
     * what a request type can be assigned. Reads the `models` relation, so
     * eager-load it where this runs on every request.
     *
     * @return list<AiModelContract>
     */
    public function textModels(): array
    {
        $seeded = $this->models->pluck('name')->all();

        return array_values(array_filter(
            AiCore::modelsWithCapability(AiCapability::Text),
            fn (AiModelContract $model): bool => $model->provider() === $this->getEnum() && in_array($model->value, $seeded, true),
        ));
    }

    public function getEnum(): ?AiProviderContract
    {
        return AiCore::providerEnum()::tryFrom($this->name);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
