<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Execution;

use AlexPavliukov\AiCore\Contracts\AiModelContract;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;

/**
 * Prices an AI request from the model's own rate card. No provider publishes
 * the cost of a call, so the project computes it.
 */
final class AiCost
{
    /**
     * The SDK's input count is a total: cached and cache-written tokens are
     * subsets of it, null from a provider that does not measure them.
     */
    public static function of(AiModelContract $model, TextUsage $usage, int $webSearchCalls = 0): float
    {
        $pricing = $model->pricing();

        $cachedTokens = min($usage->cacheReadInputTokens ?? 0, $usage->inputTokens);
        $writtenTokens = min($usage->cacheWriteInputTokens ?? 0, $usage->inputTokens - $cachedTokens);
        $billableInputTokens = $usage->inputTokens - $cachedTokens - $writtenTokens;

        $cost = ($billableInputTokens / 1_000_000) * $pricing['input']
            + ($cachedTokens / 1_000_000) * $pricing['cached']
            + ($writtenTokens / 1_000_000) * ($pricing['cache_write'] ?? $pricing['input'])
            + ($usage->outputTokens / 1_000_000) * $pricing['output']
            + ($webSearchCalls / 1000) * ($pricing['web_search'] ?? 0.0);

        if ($usage instanceof TranscriptionUsage && $usage->audioSeconds !== null) {
            $cost += ($usage->audioSeconds / 60) * ($pricing['audio_minute'] ?? 0.0);
        }

        return $cost;
    }
}
