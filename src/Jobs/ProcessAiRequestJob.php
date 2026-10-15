<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Jobs;

use AlexPavliukov\AiCore\Agents\AgentRegistry;
use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Contracts\AppliesAiResult;
use AlexPavliukov\AiCore\Exceptions\AiMisconfiguredException;
use AlexPavliukov\AiCore\Execution\AiRequestExecutor;
use AlexPavliukov\AiCore\Models\AiRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Runs one AI request off the HTTP request. Never retried: an AI call is
 * expensive and not idempotent, so a failure is reported rather than repeated.
 */
#[Timeout(AiRequestExecutor::TIMEOUT_SECONDS)]
#[Tries(1)]
final class ProcessAiRequestJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $agentState  What the worker needs to rebuild the agent.
     * @param  class-string<AppliesAiResult>|null  $applier  Writes the result when the project, not the member's screen, applies it.
     * @param  array<string, mixed>  $applyContext
     */
    public function __construct(
        public readonly int $aiRequestId,
        public readonly AiRequestTypeContract $requestType,
        public readonly string $userPrompt,
        public readonly array $agentState = [],
        public readonly ?string $applier = null,
        public readonly array $applyContext = [],
    ) {
        $connection = config('ai-core.queue.connection');

        $this->onConnection(is_string($connection) ? $connection : null);
    }

    public function handle(AiRequestExecutor $aiRequestExecutor, AgentRegistry $agents): void
    {
        $aiRequest = AiCore::requestModel()::query()->with('model')->find($this->aiRequestId);

        if (! $aiRequest instanceof AiRequest) {
            return;
        }

        // A model retired from the enum after dispatch runs on its provider's default.
        $model = $aiRequest->model?->enumOrFallback();

        if ($model === null) {
            $aiRequestExecutor->failWith(
                new AiMisconfiguredException('Neither the model of this request nor its provider is in the catalogue.'),
                $aiRequest,
                null,
                microtime(true),
            );

            return;
        }

        $applier = $this->applier;

        $aiRequestExecutor->execute(
            $aiRequest,
            $agents->make($this->requestType, $this->agentState),
            $model,
            $this->userPrompt,
            $applier === null
                ? null
                : fn (array $structured) => resolve($applier)->applyResult($aiRequest, $structured, $this->applyContext),
        );
    }

    /**
     * The job died outside the executor (a timeout, a lost worker): its row
     * fails like any other, unless the executor already finished it.
     */
    public function failed(?Throwable $exception): void
    {
        $aiRequest = AiCore::requestModel()::query()->with('model')->find($this->aiRequestId);

        if (! $aiRequest instanceof AiRequest || $aiRequest->status->isTerminal()) {
            return;
        }

        resolve(AiRequestExecutor::class)->fail(
            $aiRequest,
            $aiRequest->model?->enumOrFallback(),
            $exception?->getMessage() ?? 'Generation job failed',
            null,
        );
    }
}
