<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore;

use AlexPavliukov\AiCore\Agents\AgentRegistry;
use AlexPavliukov\AiCore\Catalog\ModelResolver;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Contracts\AppliesAiResult;
use AlexPavliukov\AiCore\Dto\AiResponseDto;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Events\AiRequestDispatching;
use AlexPavliukov\AiCore\Execution\AiRequestExecutor;
use AlexPavliukov\AiCore\Jobs\ProcessAiRequestJob;
use AlexPavliukov\AiCore\Models\AiRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\TextUsage;
use Throwable;

/**
 * The project's way into the core: pick the model, open the ledger row, run
 * the call now or on the queue, and record what came back. What differs per
 * operation is only the request message, which the caller builds from its own
 * domain input.
 */
final readonly class AiRequests
{
    public function __construct(
        private AiRequestExecutor $executor,
        private ModelResolver $models,
        private AgentRegistry $agents,
    ) {}

    /**
     * Queue the request and return the batch id to poll, or null when no
     * provider is configured.
     *
     * @param  array<string, mixed>  $agentState
     * @param  class-string<AppliesAiResult>|null  $applier  Writes the result in the worker, before the request reads completed.
     * @param  array<string, mixed>  $applyContext
     * @param  string|null  $batchId  Joins a batch already started, so one poll waits for both requests.
     */
    public function dispatch(
        AiRequestTypeContract $requestType,
        Model $requestable,
        string $userPrompt,
        array $agentState = [],
        ?string $customPrompt = null,
        ?string $applier = null,
        array $applyContext = [],
        ?string $batchId = null,
    ): ?string {
        if ($applier !== null && ! is_a($applier, AppliesAiResult::class, true)) {
            throw new InvalidArgumentException("The applier {$applier} must implement ".AppliesAiResult::class.'.');
        }

        $model = $this->textModel($requestType, $requestable);

        if (! $model instanceof AiModelContract) {
            return null;
        }

        $aiRequest = $this->open($requestable, $model, $requestType, $batchId, $customPrompt);

        dispatch(new ProcessAiRequestJob(
            $aiRequest->id,
            $requestType,
            $this->withCustomPrompt($userPrompt, $customPrompt),
            $agentState,
            $applier,
            $applyContext,
        ));

        return (string) $aiRequest->batch_id;
    }

    /**
     * Run the request in this process, for callers that already sit on a
     * queue and need the answer in hand. Null when no provider is configured.
     *
     * @param  array<string, mixed>  $agentState
     */
    public function run(
        AiRequestTypeContract $requestType,
        Model $requestable,
        string $userPrompt,
        array $agentState = [],
        ?string $customPrompt = null,
    ): ?AiResponseDto {
        $model = $this->textModel($requestType, $requestable);

        if (! $model instanceof AiModelContract) {
            return null;
        }

        return $this->executor->execute(
            $this->open($requestable, $model, $requestType, customPrompt: $customPrompt),
            $this->agents->make($requestType, $agentState),
            $model,
            $this->withCustomPrompt($userPrompt, $customPrompt),
        );
    }

    /**
     * Open a pending ledger row for a call the caller runs itself.
     *
     * @param  int|null  $userId  A follow-up made in the worker names the member of the request it follows.
     */
    public function open(
        Model $requestable,
        AiModelContract $model,
        AiRequestTypeContract $requestType,
        ?string $batchId = null,
        ?string $customPrompt = null,
        ?int $userId = null,
    ): AiRequest {
        $authId = auth()->id();
        $userId ??= is_int($authId) ? $authId : null;

        if ($batchId !== null) {
            $this->assertOwnBatch($batchId, $userId);
        }

        event(new AiRequestDispatching($requestable, $requestType, $model, $userId));

        $aiModelId = AiCore::modelModel()::query()
            ->where('name', $model->value)
            ->firstOrFail()
            ->getKey();

        $requestModel = AiCore::requestModel();
        $aiRequest = new $requestModel;

        $aiRequest->fill([
            'batch_id' => $batchId ?? (string) Str::uuid(),
            'ai_model_id' => $aiModelId,
            'user_id' => $userId,
            'requestable_type' => $requestable->getMorphClass(),
            'requestable_id' => $requestable->getKey(),
            'request_type' => $requestType,
            'status' => ProcessStatus::PENDING,
            'payload' => [],
            'custom_prompt' => filled($customPrompt) ? $customPrompt : null,
        ]);

        // Set before saving, so a project's `creating` hook can read the subject
        // (its tenant, say) without loading it again.
        $aiRequest->setRelation('requestable', $requestable);
        $aiRequest->save();

        return $aiRequest;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     */
    public function classify(AiRequest $aiRequest, array $state, array $questions, AiModelContract $model): ?ClassificationResponse
    {
        return $this->executor->classify($aiRequest, $state, $questions, $model);
    }

    /**
     * Complete a row opened with {@see open()} for a call made outside an Agent.
     *
     * @param  array<string, mixed>  $response
     */
    public function record(
        AiRequest $aiRequest,
        AiModelContract $model,
        string $text,
        array $response,
        TextUsage $usage,
        float $startedAt,
    ): AiResponseDto {
        return $this->executor->record($aiRequest, $model, $text, $response, $usage, $this->executor->elapsed($startedAt));
    }

    /**
     * Report and fail a row opened with {@see open()} whose call threw.
     */
    public function fail(AiRequest $aiRequest, AiModelContract $model, Throwable $exception, float $startedAt): AiResponseDto
    {
        return $this->executor->failWith($exception, $aiRequest, $model, $startedAt);
    }

    public function textModel(AiRequestTypeContract $requestType, Model $requestable): ?AiModelContract
    {
        $key = $requestable->getKey();

        return $this->models->forText($requestType, $requestType->name.'_'.(is_scalar($key) ? $key : ''));
    }

    public function classificationModel(): ?AiModelContract
    {
        return $this->models->forClassification();
    }

    /**
     * A request joins only a batch of the same owner: a batch id may have come
     * back from the browser. No owner (a worker, the CLI) is an owner too: it
     * joins only rows that have none, never a member's.
     */
    private function assertOwnBatch(string $batchId, ?int $userId): void
    {
        $foreign = AiCore::requestModel()::query()
            ->withoutGlobalScopes()
            ->where('batch_id', $batchId)
            ->where(static fn (Builder $query): Builder => $userId === null
                ? $query->whereNotNull('user_id')
                : $query->whereNull('user_id')->orWhere('user_id', '!=', $userId))
            ->exists();

        if ($foreign) {
            throw new InvalidArgumentException("Batch {$batchId} belongs to someone else.");
        }
    }

    /**
     * A member's Custom Prompt travels with the request message, never with the
     * instructions. It wins over the System Prompt where they disagree, but not
     * over the response format or a number the run was given in its own field:
     * the schema enforces those, and a model told otherwise is torn between two.
     */
    private function withCustomPrompt(string $userPrompt, ?string $customPrompt): string
    {
        if (blank($customPrompt)) {
            return $userPrompt;
        }

        return $userPrompt."\n\n".config()->string('ai-core.custom_prompt_heading')."\n".$customPrompt;
    }
}
