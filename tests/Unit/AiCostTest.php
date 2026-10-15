<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Tests\Unit;

use AlexPavliukov\AiCore\Execution\AiCost;
use AlexPavliukov\AiCore\Tests\Fixtures\FakeModel;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use PHPUnit\Framework\TestCase;

final class AiCostTest extends TestCase
{
    public function test_input_and_output_are_billed_at_their_rates(): void
    {
        $this->assertEqualsWithDelta(2.0, AiCost::of(FakeModel::Small, new TextUsage(inputTokens: 1_000_000, outputTokens: 500_000)), 1e-9);
    }

    public function test_cached_tokens_are_discounted_and_taken_out_of_the_full_priced_input(): void
    {
        // 0.6M × 1 + 0.4M × 0.1
        $this->assertEqualsWithDelta(0.64, AiCost::of(FakeModel::Small, new TextUsage(inputTokens: 1_000_000, cacheReadInputTokens: 400_000)), 1e-9);
    }

    public function test_cache_written_tokens_cost_the_input_rate_without_a_write_rate(): void
    {
        $this->assertEqualsWithDelta(1.0, AiCost::of(FakeModel::Small, new TextUsage(inputTokens: 1_000_000, cacheWriteInputTokens: 900_000)), 1e-9);
    }

    public function test_cached_tokens_never_exceed_the_input(): void
    {
        $this->assertEqualsWithDelta(0.01, AiCost::of(FakeModel::Small, new TextUsage(inputTokens: 100_000, cacheReadInputTokens: 500_000)), 1e-9);
    }

    public function test_a_transcription_costs_its_audio_by_the_minute(): void
    {
        $this->assertEqualsWithDelta(0.42, AiCost::of(FakeModel::Transcriber, new TranscriptionUsage(audioSeconds: 42.0)), 1e-9);
        $this->assertSame(0.0, AiCost::of(FakeModel::Transcriber, new TextUsage(inputTokens: 100, outputTokens: 100)));
    }

    public function test_web_search_calls_are_billed_per_thousand(): void
    {
        $this->assertEqualsWithDelta(0.05, AiCost::of(FakeModel::Small, new TextUsage, webSearchCalls: 5), 1e-9);
    }

    public function test_an_empty_usage_costs_nothing(): void
    {
        $this->assertSame(0.0, AiCost::of(FakeModel::Small, new TextUsage));
    }
}
