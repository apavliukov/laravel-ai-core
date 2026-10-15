<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Feature;

use AlexPavliukov\AiCore\AiRequests;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Events\AiRequestCompleted;
use AlexPavliukov\AiCore\Events\AiRequestDispatching;
use AlexPavliukov\AiCore\Events\AiRequestFailed;
use AlexPavliukov\AiCore\Exceptions\AiMisconfiguredException;
use AlexPavliukov\AiCore\Execution\AiRequestExecutor;
use AlexPavliukov\AiCore\Jobs\ProcessAiRequestJob;
use AlexPavliukov\AiCore\Models\AiModel;
use AlexPavliukov\AiCore\Models\AiProvider;
use AlexPavliukov\AiCore\Models\AiRequest;
use AlexPavliukov\AiCore\Tests\Fixtures\CannedAgent;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeAgent;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeRequestType;
use AlexPavliukov\AiCore\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;

final class AiRequestsTest extends TestCase
{
    public function test_dispatch_opens_a_pending_row_and_queues_the_prompt_with_the_custom_prompt_under_its_heading(): void
    {
        Queue::fake();
        $this->seedAiCatalog(FakeModel::Small);

        $batchId = $this->aiRequests()->dispatch(FakeRequestType::Analysis, $this->subject(), 'Analyze.', ['tone' => 'warm'], customPrompt: 'Be brief.');

        $aiRequest = AiRequest::query()->where('batch_id', $batchId)->sole();
        $this->assertSame(ProcessStatus::PENDING, $aiRequest->status);
        $this->assertSame('Be brief.', $aiRequest->custom_prompt);
        Queue::assertPushed(ProcessAiRequestJob::class, static fn (ProcessAiRequestJob $job): bool => $job->aiRequestId === $aiRequest->id
            && $job->agentState === ['tone' => 'warm']
            && str_starts_with($job->userPrompt, "Analyze.\n\nADDITIONAL INSTRUCTIONS")
            && str_ends_with($job->userPrompt, "\nBe brief."));
    }

    public function test_no_enabled_provider_is_a_misconfiguration_not_a_silent_no_op(): void
    {
        Queue::fake();

        try {
            $this->aiRequests()->dispatch(FakeRequestType::Analysis, $this->subject(), 'Analyze.');
            $this->fail('Nothing enabled must not dispatch quietly.');
        } catch (AiMisconfiguredException) {
            Queue::assertNothingPushed();
            $this->assertSame(0, AiRequest::query()->count());
        }
    }

    public function test_enabled_providers_that_cannot_answer_are_a_misconfiguration_too(): void
    {
        $this->seedAiCatalog(FakeModel::Large);

        $this->expectException(AiMisconfiguredException::class);

        $this->aiRequests()->run(FakeRequestType::Search, $this->subject(), 'Find.');
    }

    public function test_a_listener_that_throws_on_dispatching_stops_the_request_before_its_row(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        Event::listen(AiRequestDispatching::class, static fn (): never => throw new RuntimeException('Quota exceeded'));

        try {
            $this->aiRequests()->dispatch(FakeRequestType::Analysis, $this->subject(), 'Analyze.');
            $this->fail('The listener did not stop the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Quota exceeded', $exception->getMessage());
        }

        $this->assertSame(0, AiRequest::query()->count());
    }

    public function test_a_run_records_the_sdk_usage_the_cost_and_the_agent_state(): void
    {
        Event::fake([AiRequestCompleted::class]);
        $this->seedAiCatalog(FakeModel::Small);
        $this->fakeAgents([new TextResponse('Done.', new TextUsage(inputTokens: 1_000_000, outputTokens: 500_000, cacheReadInputTokens: 200_000), new Meta('openai', 'small'))]);

        $response = $this->aiRequests()->run(FakeRequestType::Analysis, $this->subject(), 'Analyze.', ['tone' => 'warm']);

        $aiRequest = AiRequest::query()->sole();
        $this->assertTrue($response->success);
        $this->assertSame(ProcessStatus::COMPLETED, $aiRequest->status);
        $this->assertSame([
            'input_tokens' => 1_000_000,
            'output_tokens' => 500_000,
            'cache_read_input_tokens' => 200_000,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
            'web_search_calls' => 0,
        ], $aiRequest->tokens?->toArray());
        // 0.8M input × 1 + 0.2M cached × 0.1 + 0.5M output × 2
        $this->assertSame('1.820000', $aiRequest->api_cost);
        $this->assertSame(['tone' => 'warm'], $aiRequest->payload?->toArray()['agent_state']);
        $this->assertStringContainsString('System, warm.', (string) json_encode($aiRequest->payload));
        Event::assertDispatched(AiRequestCompleted::class);
    }

    public function test_web_search_calls_are_counted_and_priced_per_thousand(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $aiRequest = $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis);
        $search = new ProviderToolCall('ws_1', 'web_search_call', []);
        $response = new AgentResponse('inv-1', 'Found.', new TextUsage, new Meta('openai', 'small'));
        $response->withSteps(collect([
            new Step('', [], [], FinishReason::ToolCalls, new TextUsage, $response->meta, '', [], [$search, $search]),
            new Step('Found.', [], [], FinishReason::Stop, new TextUsage, $response->meta, '', [], [$search, new ProviderToolCall('m_1', 'message', [])]),
        ]));

        resolve(AiRequestExecutor::class)->execute($aiRequest, new CannedAgent($response), FakeModel::Small, 'Search.');

        $aiRequest->refresh();
        $this->assertSame(3, $aiRequest->tokens?->toArray()['web_search_calls']);
        $this->assertSame('0.030000', $aiRequest->api_cost);
    }

    public function test_an_answer_cut_off_by_the_token_limit_fails_the_request(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $aiRequest = $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis);
        $response = new AgentResponse('inv-2', '{"half', new TextUsage, new Meta('openai', 'small'));
        $response->withSteps(collect([new Step('{"half', [], [], FinishReason::Length, new TextUsage, $response->meta, '', [])]));

        $result = resolve(AiRequestExecutor::class)->execute($aiRequest, new CannedAgent($response), FakeModel::Small, 'Analyze.');

        $this->assertFalse($result->success);
        $this->assertSame(ProcessStatus::FAILED, $aiRequest->refresh()->status);
    }

    public function test_a_call_made_outside_an_agent_is_recorded_with_its_own_usage(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $aiRequest = $this->aiRequests()->open($this->subject(), FakeModel::Transcriber, FakeRequestType::Check);

        $result = $this->aiRequests()->record($aiRequest, FakeModel::Transcriber, ' Hello ', ['text' => ' Hello '], new TranscriptionUsage(audioSeconds: 30.0), microtime(true));

        $this->assertSame('Hello', $result->text);
        $this->assertEquals(30.0, $aiRequest->refresh()->tokens?->get('audio_seconds'));
        // 30 s = 0.5 min × 0.6
        $this->assertSame('0.300000', $aiRequest->api_cost);
    }

    public function test_the_queued_job_rebuilds_the_agent_and_completes_the_row(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        FakeAgent::fake(['Done.']);

        $batchId = $this->aiRequests()->dispatch(FakeRequestType::Analysis, $this->subject(), 'Analyze.', ['tone' => 'warm']);

        $aiRequest = AiRequest::query()->where('batch_id', $batchId)->sole();
        $this->assertSame(ProcessStatus::COMPLETED, $aiRequest->status);
        $this->assertSame('Done.', $aiRequest->responseText());
    }

    public function test_a_queued_request_whose_model_and_provider_left_the_catalogue_fails_through_the_executor(): void
    {
        Event::fake([AiRequestFailed::class]);
        $provider = AiProvider::query()->create(['name' => 'retired-lab', 'display_name' => 'Retired']);
        $model = AiModel::query()->create(['ai_provider_id' => $provider->id, 'name' => 'retired-model', 'display_name' => 'Retired']);
        $provider->delete();
        $aiRequest = AiRequest::query()->create([
            'ai_model_id' => $model->id,
            'requestable_type' => $this->subject()->getMorphClass(),
            'requestable_id' => 1,
            'request_type' => FakeRequestType::Analysis,
            'status' => ProcessStatus::PENDING,
            'payload' => [],
        ]);

        dispatch(new ProcessAiRequestJob($aiRequest->id, FakeRequestType::Analysis, 'Analyze.'));

        $this->assertSame(ProcessStatus::FAILED, $aiRequest->refresh()->status);
        Event::assertDispatched(AiRequestFailed::class);
    }

    private function aiRequests(): AiRequests
    {
        return resolve(AiRequests::class);
    }
}
