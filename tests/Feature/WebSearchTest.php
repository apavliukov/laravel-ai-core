<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Feature;

use AlexPavliukov\AiCore\AiRequests;
use AlexPavliukov\AiCore\Catalog\ModelAssignments;
use AlexPavliukov\AiCore\Catalog\ModelResolver;
use AlexPavliukov\AiCore\Contracts\AiRequestTypeContract;
use AlexPavliukov\AiCore\Enums\ProcessStatus;
use AlexPavliukov\AiCore\Exceptions\AiMisconfiguredException;
use AlexPavliukov\AiCore\Execution\AiRequestExecutor;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeRequestType;
use AlexPavliukov\AiCore\Tests\Fixtures\SearchingAgent;
use AlexPavliukov\AiCore\Tests\TestCase;

final class WebSearchTest extends TestCase
{
    public function test_a_searching_type_runs_only_on_an_active_model_that_can_search(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        $this->assertSame(FakeModel::Small, $this->resolver()->forText(FakeRequestType::Search, 'test'));

        $this->seedAiCatalog(FakeModel::Large);
        $this->assertNull($this->resolver()->forText(FakeRequestType::Search, 'test'));
        $this->assertSame(FakeModel::Large, $this->resolver()->forText(FakeRequestType::Analysis, 'test'));
    }

    public function test_an_assigned_model_that_cannot_search_stops_the_request_before_the_provider(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        app()->instance(ModelAssignments::class, new class extends ModelAssignments
        {
            public function assigned(AiRequestTypeContract $type): string
            {
                return FakeModel::Large->value;
            }
        });

        $this->expectException(AiMisconfiguredException::class);

        resolve(AiRequests::class)->dispatch(FakeRequestType::Search, $this->subject(), 'Find.');
    }

    public function test_the_executor_fails_a_searching_agent_on_a_model_that_cannot_search(): void
    {
        $this->seedAiCatalog(FakeModel::Small);
        SearchingAgent::fake(['Found.']);
        $aiRequest = resolve(AiRequests::class)->open($this->subject(), FakeModel::Large, FakeRequestType::Search);

        $result = resolve(AiRequestExecutor::class)->execute($aiRequest, new SearchingAgent, FakeModel::Large, 'Find.');

        $this->assertFalse($result->success);
        $this->assertSame(ProcessStatus::FAILED, $aiRequest->refresh()->status);
        SearchingAgent::assertNeverPrompted();
    }

    private function resolver(): ModelResolver
    {
        return resolve(ModelResolver::class);
    }
}
