<?php

namespace App\DataTransferObjects\Onboarding;

/**
 * Parsed and retained in the normalized result, but not written to any live table
 * in Task 1 — deferred to Task 2.
 */
final readonly class FaqEntryData
{
    public function __construct(
        public ?ImportedFact $question = null,
        public ?ImportedFact $answer = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            question: isset($raw['question']) ? ImportedFact::fromArray($raw['question']) : null,
            answer: isset($raw['answer']) ? ImportedFact::fromArray($raw['answer']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'question' => $this->question?->toArray(),
            'answer' => $this->answer?->toArray(),
        ];
    }
}
