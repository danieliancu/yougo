<?php

namespace App\DataTransferObjects\Onboarding;

/**
 * Parsed and retained in the normalized result, but not written to any live table
 * in Task 1 — deferred to Task 2.
 */
final readonly class PolicyEntryData
{
    public function __construct(
        public ?ImportedFact $title = null,
        public ?ImportedFact $content = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            title: isset($raw['title']) ? ImportedFact::fromArray($raw['title']) : null,
            content: isset($raw['content']) ? ImportedFact::fromArray($raw['content']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title?->toArray(),
            'content' => $this->content?->toArray(),
        ];
    }
}
