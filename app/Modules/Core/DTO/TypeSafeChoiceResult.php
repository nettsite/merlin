<?php

namespace App\Modules\Core\DTO;

class TypeSafeChoiceResult
{
    /**
     * @param  array<string, float>  $probabilities
     */
    public function __construct(
        public readonly string $choice,
        public readonly float $confidence,
        public readonly array $probabilities,
    ) {}

    /**
     * @param  array<string, mixed>  $answer  The "answers.<id>" object from a Choice question.
     */
    public static function fromApiResponse(array $answer): self
    {
        return new self(
            choice: $answer['choice'],
            confidence: (float) $answer['confidence'],
            probabilities: $answer['probabilities'],
        );
    }
}
