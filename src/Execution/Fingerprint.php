<?php

declare(strict_types=1);

namespace AlexPavliukov\AiCore\Execution;

use AlexPavliukov\AiCore\Contracts\AiModelContract;
use Laravel\Ai\Contracts\Question;

/**
 * What a request asks, as one hash stored on its row, so a guard can tell
 * whether a request it would make now asks the same.
 */
final class Fingerprint
{
    public static function text(AiModelContract $model, string $instructions, string $userPrompt): string
    {
        return self::hash(['model' => $model->value, 'instructions' => $instructions, 'message' => $userPrompt]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     */
    public static function classification(AiModelContract $model, array $state, array $questions): string
    {
        return self::hash(['model' => $model->value, ...self::classificationPayload($state, $questions)]);
    }

    /**
     * What a Classification is recorded as having been asked.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @return array{state: array<string, mixed>, questions: array<string, array<string, mixed>>}
     */
    public static function classificationPayload(array $state, array $questions): array
    {
        return [
            'state' => $state,
            'questions' => array_map(static fn (Question $question): array => $question->toArray(), $questions),
        ];
    }

    /**
     * Through JSON first, so values take the shape they would take stored, then
     * with object keys sorted. Lists keep their order: a reordered scale is
     * another question.
     *
     * @param  array<array-key, mixed>  $asked
     */
    private static function hash(array $asked): string
    {
        return hash('sha256', (string) json_encode(self::canonical((array) json_decode((string) json_encode($asked), true))));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        $value = array_map(static fn (mixed $item): mixed => is_array($item) ? self::canonical($item) : $item, $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
