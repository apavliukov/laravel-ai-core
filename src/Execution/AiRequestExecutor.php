<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Execution;

use AlexPavliukov\AiCore\Agents\BaseAgent;
use AlexPavliukov\AiCore\AiRequests;
use AlexPavliukov\AiCore\Contracts\AiModelContract;
use AlexPavliukov\AiCore\Dto\AiResponseDto;
use AlexPavliukov\AiCore\Enums\AiCapability;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Events\AiRequestCompleted;
use AlexPavliukov\AiCore\Events\AiRequestFailed;
use AlexPavliukov\AiCore\Exceptions\AiMisconfiguredException;
use AlexPavliukov\AiCore\Exceptions\AiRequestFailedException;
use AlexPavliukov\AiCore\Models\AiRequest;
use Closure;
use Laravel\Ai\Classification;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Runs one call against the provider and writes everything the ledger keeps
 * about it into its AI Request row: what was sent, the answer, the tokens, the
 * cost and the outcome.
 *
 * @internal Reached through {@see AiRequests} and the job.
 */
final readonly class AiRequestExecutor
{
    /**
     * Wall-clock budget for the queued job that wraps a single AI request.
     */
    public const int TIMEOUT_SECONDS = 360;

    /**
     * Budget for the provider call itself. Below the job timeout so a stalled
     * provider fails the request with its own error instead of being killed
     * by the queue, and far above it sits the connection's retry_after.
     */
    public const int PROMPT_TIMEOUT_SECONDS = 300;

    /**
     * Budget for a Classification. The model answers in milliseconds and the
     * call can run inside a member's request, so it is kept short.
     */
    public const int CLASSIFICATION_TIMEOUT_SECONDS = 30;

    public function execute(
        AiRequest $aiRequest,
        BaseAgent $agent,
        AiModelContract $model,
        string $userPrompt,
        ?Closure $beforeComplete = null,
    ): AiResponseDto {
        $payload = [
            'model' => $model->value,
            // What the worker was given to rebuild this agent. Kept on the row so
            // anything reading the result later can rebuild it too.
            'agent_state' => $agent->state(),
            'input' => [
                ['role' => 'system', 'content' => $agent->instructions()],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $aiRequest->update([
            'status' => ProcessStatus::PROCESSING,
            'payload' => $payload,
            'fingerprint' => Fingerprint::text($model, $agent->instructions(), $userPrompt),
        ]);

        $startedAt = microtime(true);

        if ($this->searchesTheWeb($agent) && ! in_array(AiCapability::WebSearch, $model->capabilities(), true)) {
            return $this->failWith(
                new AiMisconfiguredException('This request searches the web, which its model cannot do.', [
                    'agent' => $agent::class,
                    'model' => $model->value,
                ]),
                $aiRequest,
                $model,
                $startedAt,
            );
        }

        try {
            $response = $agent->prompt(
                $userPrompt,
                provider: $model->provider()->lab(),
                model: (string) $model->value,
                timeout: self::PROMPT_TIMEOUT_SECONDS,
            );
        } catch (Throwable $exception) {
            return $this->failWith($exception, $aiRequest, $model, $startedAt);
        }

        $truncated = $response->steps
            ->contains(static fn (Step $step): bool => $step->finishReason === FinishReason::Length);

        if ($truncated) {
            return $this->fail(
                $aiRequest,
                'The answer was cut off before it finished (incomplete: max output tokens).',
                $this->elapsed($startedAt),
            );
        }

        $structured = $response instanceof StructuredAgentResponse ? $response->structured : null;

        if ($structured !== null && ($rejection = $agent->rejection($structured)) !== null) {
            return $this->fail($aiRequest, $rejection, $this->elapsed($startedAt));
        }

        // A result the project writes itself is written before the row says
        // completed, so a poll that sees the status always sees the result.
        if ($structured !== null && $beforeComplete instanceof Closure) {
            try {
                $beforeComplete($structured);
            } catch (Throwable $exception) {
                return $this->failWith($exception, $aiRequest, $model, $startedAt);
            }
        }

        // ponytail: matches OpenAI's `web_search_call` only; Anthropic reports its
        // search as `server_tool_use`. When a second lab searches, match the type
        // by $model->provider()->lab().
        $webSearchCalls = $response->steps->sum(
            static fn (Step $step): int => count(array_filter(
                $step->providerToolCalls,
                static fn (ProviderToolCall $call): bool => $call->type === 'web_search_call',
            )),
        );

        // A structured answer is stored once, as data; its raw JSON is the
        // same bytes again. Plain-text agents keep the text, their only copy.
        return $this->complete(
            $aiRequest,
            response: [
                ...($structured === null ? ['text' => $response->text] : ['structured' => $structured]),
                'meta' => $response->meta->toArray(),
            ],
            usage: [...$response->usage->toArray(), 'web_search_calls' => $webSearchCalls],
            cost: AiCost::of($model, $response->usage, $webSearchCalls),
            responseTime: $this->elapsed($startedAt),
        );
    }

    /**
     * Ask the classification model the given questions about the state, and
     * record the call in its AI Request row. Returns null when the call failed;
     * the row then carries the provider's error.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     */
    public function classify(
        AiRequest $aiRequest,
        array $state,
        array $questions,
        AiModelContract $model,
    ): ?ClassificationResponse {
        $aiRequest->update([
            'status' => ProcessStatus::PROCESSING,
            'payload' => ['model' => $model->value, ...Fingerprint::classificationPayload($state, $questions)],
            'fingerprint' => Fingerprint::classification($model, $state, $questions),
        ]);

        $startedAt = microtime(true);

        try {
            $response = Classification::of($state)
                ->questions($questions)
                ->timeout(self::CLASSIFICATION_TIMEOUT_SECONDS)
                ->classify($model->provider()->lab(), (string) $model->value);
        } catch (Throwable $exception) {
            $this->failWith($exception, $aiRequest, $model, $startedAt);

            return null;
        }

        $this->complete(
            $aiRequest,
            response: [
                'answers' => array_map(static fn (Answer $answer): array => $answer->toArray(), $response->answers),
                'meta' => $response->meta->toArray(),
            ],
            usage: $response->usage->toArray(),
            cost: AiCost::of($model, $response->usage),
            responseTime: $this->elapsed($startedAt),
        );

        return $response;
    }

    /**
     * Record the outcome of a call the project made itself, outside an Agent
     * (a transcription), from the SDK's usage of that call.
     *
     * @param  array<string, mixed>  $response
     */
    public function record(
        AiRequest $aiRequest,
        AiModelContract $model,
        string $text,
        array $response,
        TextUsage $usage,
        int $responseTime,
    ): AiResponseDto {
        return $this->complete(
            $aiRequest,
            response: $response + ['text' => $text],
            usage: $usage->toArray(),
            cost: AiCost::of($model, $usage),
            responseTime: $responseTime,
        );
    }

    /**
     * Report a call that threw and fail its row with a message safe to show.
     */
    public function failWith(Throwable $exception, AiRequest $aiRequest, ?AiModelContract $model, float $startedAt): AiResponseDto
    {
        $this->report($exception, $aiRequest, $model);

        return $this->fail($aiRequest, self::publicMessage($exception, $aiRequest), $this->elapsed($startedAt));
    }

    /**
     * What a member may read about a failure. The ledger's error reaches the
     * screen, and a provider's or the host's own exception can carry account
     * details, request bodies or SQL; only the SDK's templated provider errors
     * and the core's configuration errors are kept, the rest is reported in
     * full and shown as a reference.
     */
    public static function publicMessage(?Throwable $exception, AiRequest $aiRequest): string
    {
        if ($exception instanceof FailoverableException || $exception instanceof AiMisconfiguredException) {
            return $exception->getMessage();
        }

        return "The AI request failed (ref #{$aiRequest->id}).";
    }

    /**
     * @param  int|null  $responseTime  Null when no call was timed (a job that died or never ran).
     */
    public function fail(
        AiRequest $aiRequest,
        string $error,
        ?int $responseTime,
    ): AiResponseDto {
        $aiRequest->update([
            'status' => ProcessStatus::FAILED,
            'response_time' => $responseTime,
            'error_message' => $error,
        ]);

        $aiResponse = AiResponseDto::fromRequest($aiRequest);

        event(new AiRequestFailed($aiRequest, $aiResponse));

        return $aiResponse;
    }

    /**
     * A failed provider call only reaches the member as a message, and the job
     * itself succeeds, so without this a broken key or a dead provider would
     * leave no trace anywhere. Prompts are deliberately not included: they
     * carry the project's private data.
     */
    public function report(Throwable $exception, AiRequest $aiRequest, ?AiModelContract $model): void
    {
        report(new AiRequestFailedException($exception, [
            'ai_request_id' => $aiRequest->id,
            'request_type' => $aiRequest->request_type->name,
            'model' => $model?->value,
            ...($exception instanceof AiMisconfiguredException ? $exception->context() : []),
        ]));
    }

    public function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function searchesTheWeb(BaseAgent $agent): bool
    {
        if (! $agent instanceof HasTools) {
            return false;
        }

        foreach ($agent->tools() as $tool) {
            if ($tool instanceof WebSearch) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $usage
     */
    private function complete(AiRequest $aiRequest, array $response, array $usage, float $cost, int $responseTime): AiResponseDto
    {
        $aiRequest->update([
            'status' => ProcessStatus::COMPLETED,
            'response' => $response,
            'tokens' => $usage,
            'api_cost' => $cost,
            'response_time' => $responseTime,
            'error_message' => null,
        ]);

        $aiResponse = AiResponseDto::fromRequest($aiRequest);

        event(new AiRequestCompleted($aiRequest, $aiResponse));

        return $aiResponse;
    }
}
