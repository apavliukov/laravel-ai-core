<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Feature;

use AlexPavliukov\AiCore\AiCore;
use AlexPavliukov\AiCore\AiRequests;
use AlexPavliukov\AiCore\Catalog\ModelAssignments;
use AlexPavliukov\AiCore\Catalog\ModelResolver;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Events\AiRequestCompleted;
use AlexPavliukov\AiCore\Events\AiRequestDispatching;
use AlexPavliukov\AiCore\Events\AiRequestFailed;
use AlexPavliukov\AiCore\Exceptions\AiMisconfiguredException;
use AlexPavliukov\AiCore\Exceptions\AiRequestRejectedException;
use AlexPavliukov\AiCore\Execution\AiRequestExecutor;
use AlexPavliukov\AiCore\Models\AiModel;
use AlexPavliukov\AiCore\Models\AiProvider;
use AlexPavliukov\AiCore\Models\AiRequest;
use AlexPavliukov\AiCore\Tests\Fixtures\CannedAgent;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeAgent;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeRequestType;
use AlexPavliukov\AiCore\Tests\Fixtures\HostAiRequest;
use AlexPavliukov\AiCore\Tests\Fixtures\HostAiRequestFactory;
use AlexPavliukov\AiCore\Tests\Fixtures\OwnedSubject;
use AlexPavliukov\AiCore\Tests\Fixtures\TenantAiRequest;
use AlexPavliukov\AiCore\Tests\TestCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use LogicException;

final class HostExtensionsTest extends TestCase
{
    public function test_the_subject_key_is_stamped_from_a_loaded_subject_only(): void
    {
        config(['ai-core.subject_key.column' => 'custom_prompt']);
        $this->seedAiCatalog(FakeModel::Small);

        $opened = $this->aiRequests()->open(OwnedSubject::query()->create(), FakeModel::Small, FakeRequestType::Analysis);
        $this->assertSame('owner-42', $opened->refresh()->custom_prompt);

        $factoryRow = AiRequest::factory()->for($this->subject(), 'requestable')->create(['custom_prompt' => 'set-by-seeder']);
        $this->assertSame('set-by-seeder', $factoryRow->refresh()->custom_prompt);

        $this->expectException(InvalidArgumentException::class);
        $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis);
    }

    public function test_only_a_model_that_can_run_the_type_is_assignable(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $assignments = new class extends ModelAssignments
        {
            public ?string $stored = 'untouched';

            protected function store(AiRequestTypeContract $type, ?string $model): void
            {
                $this->stored = $model;
            }
        };

        $this->assertSame([FakeModel::Small, FakeModel::Large], resolve(ModelResolver::class)->assignableModels(FakeRequestType::Analysis));
        $this->assertSame([FakeModel::Small], resolve(ModelResolver::class)->assignableModels(FakeRequestType::Search));

        $assignments->assign(FakeRequestType::Analysis, FakeModel::Large->value);
        $this->assertSame('large', $assignments->stored);

        $assignments->assign(FakeRequestType::Analysis, '');
        $this->assertNull($assignments->stored);

        $this->expectException(AiMisconfiguredException::class);
        $assignments->assign(FakeRequestType::Search, FakeModel::Large->value);
    }

    public function test_the_default_assignments_store_nothing(): void
    {
        $this->seedAiCatalog(FakeModel::Small);

        $this->assertNull(new ModelAssignments()->assigned(FakeRequestType::Analysis));

        $this->expectException(LogicException::class);
        new ModelAssignments()->assign(FakeRequestType::Analysis, FakeModel::Large->value);
    }

    public function test_a_truncated_and_a_rejected_answer_are_each_reported_once(): void
    {
        Exceptions::fake();
        Event::fake([AiRequestFailed::class]);
        $this->seedAiCatalog(FakeModel::Small);
        $executor = resolve(AiRequestExecutor::class);

        $truncated = new AgentResponse('inv-1', '{"half', new TextUsage, new Meta('openai', 'small'));
        $truncated->withSteps(collect([new Step('{"half', [], [], FinishReason::Length, new TextUsage, $truncated->meta, '', [])]));
        $cut = $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis);
        $executor->execute($cut, new CannedAgent($truncated), FakeModel::Small, 'Analyze.');

        $answer = new StructuredAgentResponse('inv-2', ['items' => []], '{"items":[]}', new TextUsage, new Meta('openai', 'small'));
        $answer->withSteps(collect([new Step('{"items":[]}', [], [], FinishReason::Stop, new TextUsage, $answer->meta, '', [])]));
        $rejected = $this->aiRequests()->open($this->subject(), FakeModel::Small, FakeRequestType::Analysis);
        $executor->execute($rejected, new RejectingAgent($answer), FakeModel::Small, 'Analyze.');

        Exceptions::assertReported(static fn (AiRequestRejectedException $exception): bool => $exception->context()['ai_request_id'] === $cut->id);
        Exceptions::assertReported(static fn (AiRequestRejectedException $exception): bool => $exception->context()['ai_request_id'] === $rejected->id
            && str_contains($exception->getMessage(), 'No items.'));
        $this->assertSame('No items.', $rejected->refresh()->error_message);
        Event::assertDispatchedTimes(AiRequestFailed::class, 2);
    }

    public function test_the_factory_writes_the_shapes_the_executor_writes_without_events(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        Event::fake([AiRequestDispatching::class, AiRequestCompleted::class, AiRequestFailed::class]);

        $text = AiRequest::factory()->for($this->subject(), 'requestable')->forModel(FakeModel::Small)
            ->completed('Done.', new TextUsage(inputTokens: 1_000_000), ['input' => []], webSearchCalls: 2)->create();
        $structured = AiRequest::factory()->for($this->subject(), 'requestable')->forModel(FakeModel::Small)
            ->completed(['items' => [1]], new TextUsage)->create();
        $audio = AiRequest::factory()->for($this->subject(), 'requestable')->forModel(FakeModel::Transcriber)
            ->transcribed('Hello', new TranscriptionUsage(audioSeconds: 60.0))->create();
        $failed = AiRequest::factory()->for($this->subject(), 'requestable')->failed('Nope.')->create();

        $this->assertSame('Done.', $text->refresh()->responseText());
        $this->assertSame(2, $text->tokens?->toArray()['web_search_calls']);
        $this->assertSame('1.020000', $text->api_cost);
        $this->assertSame(['items' => [1]], $structured->refresh()->response?->toArray()['structured']);
        $this->assertSame('0.600000', $audio->refresh()->api_cost);
        $this->assertSame(ProcessStatus::FAILED, $failed->refresh()->status);
        Event::assertNotDispatched(AiRequestDispatching::class);
        Event::assertNotDispatched(AiRequestCompleted::class);
        Event::assertNotDispatched(AiRequestFailed::class);
    }

    public function test_a_host_subclass_keeps_its_own_factory_and_the_base_model_gets_the_package_one(): void
    {
        Factory::guessFactoryNamesUsing(static fn (string $model): string => HostAiRequestFactory::class);

        try {
            $this->assertInstanceOf(HostAiRequestFactory::class, HostAiRequest::factory());
            $this->assertNotInstanceOf(HostAiRequestFactory::class, AiRequest::factory());
        } finally {
            Factory::flushState();
        }
    }

    public function test_the_subject_key_is_stamped_before_a_host_trait_hook_runs(): void
    {
        config(['ai-core.subject_key.column' => 'custom_prompt', 'ai-core.models.request' => TenantAiRequest::class]);
        $this->seedAiCatalog(FakeModel::Small);
        TenantAiRequest::$seenOnCreating = 'not run';

        $this->aiRequests()->open(OwnedSubject::query()->create(), FakeModel::Small, FakeRequestType::Analysis);

        $this->assertSame('owner-42', TenantAiRequest::$seenOnCreating);
    }

    public function test_a_factory_row_for_a_subject_is_stamped_like_a_request(): void
    {
        config(['ai-core.subject_key.column' => 'custom_prompt']);
        $this->seedAiCatalog(FakeModel::Small);

        $row = AiRequest::factory()->forSubject(OwnedSubject::query()->create())->create();

        $this->assertSame('owner-42', $row->refresh()->custom_prompt);
    }

    public function test_the_enabled_providers_are_read_once_per_request_until_one_is_saved(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        DB::enableQueryLog();

        resolve(ModelResolver::class)->assignableModels(FakeRequestType::Analysis);
        resolve(ModelResolver::class)->assignableModels(FakeRequestType::Search);
        $this->assertCount(1, $this->queriesOn(AiCore::table('providers')));

        AiProvider::query()->where('name', 'openai')->sole()->update(['is_active' => false]);
        $this->assertSame([], resolve(ModelResolver::class)->assignableModels(FakeRequestType::Analysis));
    }

    public function test_a_factory_row_looks_its_model_up_once_and_needs_a_seeded_catalogue(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        DB::enableQueryLog();

        AiRequest::factory()->count(3)->for($this->subject(), 'requestable')->forModel(FakeModel::Small)->completed('Done.', new TextUsage)->create();

        $this->assertCount(3, $this->queriesOn(AiCore::table('models')));

        AiModel::query()->named(FakeModel::Large)->delete();
        $this->expectException(LogicException::class);
        AiRequest::factory()->for($this->subject(), 'requestable')->forModel(FakeModel::Large)->make();
    }

    /**
     * @return list<string>
     */
    private function queriesOn(string $table): array
    {
        return array_values(array_filter(
            array_map(static fn (array $query): string => (string) $query['query'], DB::getQueryLog()),
            static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, "from \"{$table}\""),
        ));
    }

    private function aiRequests(): AiRequests
    {
        return resolve(AiRequests::class);
    }
}

final class RejectingAgent extends FakeAgent
{
    public function __construct(private readonly AgentResponse $canned)
    {
        parent::__construct();
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @param  Lab|array<int, mixed>|string|null  $provider
     */
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        return $this->canned;
    }

    protected function validate(array $structured): string
    {
        return 'No items.';
    }
}
