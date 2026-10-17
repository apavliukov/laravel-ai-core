<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Models;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiRequestable;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Database\Factories\AiRequestFactory;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsFluent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Fluent;
use InvalidArgumentException;

/**
 * One call to a provider, as the ledger keeps it. Tenancy and display
 * accessors belong to the project's subclass; the subject key column, when
 * configured, is stamped here.
 *
 * @property int $id
 * @property int $ai_model_id
 * @property int|string|null $user_id
 * @property string $requestable_type
 * @property int|string $requestable_id
 * @property string|null $batch_id
 * @property AiRequestTypeContract $request_type
 * @property ProcessStatus $status
 * @property Fluent<string, mixed>|null $payload
 * @property Fluent<string, mixed>|null $response
 * @property Fluent<string, mixed>|null $tokens
 * @property string|null $api_cost
 * @property int|null $response_time
 * @property string|null $error_message
 * @property string|null $fingerprint
 * @property string|null $custom_prompt
 * @property-read AiModel|null $model
 */
#[Fillable([
    'user_id',
    'batch_id',
    'ai_model_id',
    'requestable_type',
    'requestable_id',
    'request_type',
    'status',
    'payload',
    'fingerprint',
    'response',
    'tokens',
    'api_cost',
    'response_time',
    'error_message',
    'custom_prompt',
])]
class AiRequest extends Model
{
    /** @use HasFactory<AiRequestFactory> */
    use HasFactory;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = AiCore::table('requests');
    }

    /**
     * Stamps the configured subject key from the subject on a row being
     * created, when the subject is loaded (AiRequests::open() sets it). The
     * subject decides, not the session: a worker, the CLI or a platform admin
     * has no current tenant, or another one.
     */
    #[Boot]
    public static function stampSubjectKey(): void
    {
        static::creating(static function (self $aiRequest): void {
            $column = AiCore::subjectKeyColumn();

            if ($column === null || ! $aiRequest->relationLoaded('requestable')) {
                return;
            }

            $requestable = $aiRequest->getRelation('requestable');

            if (! $requestable instanceof AiRequestable) {
                throw new InvalidArgumentException(sprintf(
                    '%s must implement %s to stamp %s on an AI request.',
                    is_object($requestable) ? $requestable::class : 'null',
                    AiRequestable::class,
                    $column,
                ));
            }

            $aiRequest->setAttribute($column, $requestable->aiSubjectKey());
        });
    }

    /**
     * A factory or a seeder names the subject key column like any other.
     */
    #[Initialize]
    public function mergeSubjectKeyFillable(): void
    {
        $column = AiCore::subjectKeyColumn();

        if ($column !== null) {
            $this->mergeFillable([$column]);
        }
    }

    /**
     * The fingerprint of the last completed request of a type for this subject;
     * null when there was none, or it was recorded before fingerprints were
     * kept. The guards that keep an unchanged recompute from asking again
     * compare with it, so null never matches.
     */
    public static function lastCompletedFingerprint(Model $requestable, AiRequestTypeContract $requestType): ?string
    {
        $fingerprint = static::query()
            ->about($requestable, $requestType)
            ->where('status', ProcessStatus::COMPLETED)
            ->latest('id')
            ->value('fingerprint');

        return is_string($fingerprint) ? $fingerprint : null;
    }

    /**
     * Retired models are soft-deleted, and a request keeps the model it ran on.
     *
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        /** @var BelongsTo<AiModel, $this> */
        return $this->belongsTo(AiCore::modelModel(), 'ai_model_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->belongsTo($userModel);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function requestable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The answer as text: the text of a plain answer, the JSON of a structured one.
     */
    public function responseText(): string
    {
        $response = $this->response?->toArray() ?? [];

        if (isset($response['text']) && is_string($response['text'])) {
            return $response['text'];
        }

        if (isset($response['structured']) && is_array($response['structured'])) {
            return json_encode($response['structured'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return '';
    }

    /**
     * The requests of one type made about one subject.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function about(Builder $query, Model $requestable, AiRequestTypeContract $requestType): Builder
    {
        return $query->whereMorphedTo('requestable', $requestable)->where('request_type', $requestType);
    }

    /**
     * The package factory, unless the host's subclass has its own factory by
     * Laravel's naming convention: then that one, so a host's tenant columns
     * and states are never swapped for the package's.
     *
     * @return Factory<static>|null
     */
    protected static function newFactory(): ?Factory
    {
        if (static::class !== self::class && class_exists(Factory::resolveFactoryName(static::class))) {
            return null;
        }

        /** @var Factory<static> */
        return AiRequestFactory::new();
    }

    protected function casts(): array
    {
        return [
            'request_type' => AiCore::requestTypeEnum(),
            'status' => ProcessStatus::class,
            'payload' => AsFluent::class,
            'response' => AsFluent::class,
            'tokens' => AsFluent::class,
            'api_cost' => 'decimal:6',
        ];
    }
}
