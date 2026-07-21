<?php

namespace App\DataTransferObjects\Onboarding;

use Illuminate\Support\Str;

final readonly class FaqEntryData
{
    /**
     * @param  list<string>  $sourceUrls  normalized, deduplicated pages this FAQ entry was found on — provenance only, never part of identity
     */
    public function __construct(
        public ?ImportedFact $question = null,
        public ?ImportedFact $answer = null,
        public array $sourceUrls = [],
        public ?string $externalId = null,
        public ?string $fingerprint = null,
        public bool $isTemporaryFingerprint = false,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            question: isset($raw['question']) ? ImportedFact::fromArray($raw['question']) : null,
            answer: isset($raw['answer']) ? ImportedFact::fromArray($raw['answer']) : null,
            sourceUrls: array_values(array_unique(array_map('strval', $raw['source_urls'] ?? []))),
            externalId: isset($raw['external_id']) ? (string) $raw['external_id'] : null,
            fingerprint: isset($raw['fingerprint']) ? (string) $raw['fingerprint'] : null,
            isTemporaryFingerprint: (bool) ($raw['is_temporary_fingerprint'] ?? false),
        );
    }

    /**
     * @return array<string, ?ImportedFact>
     */
    public function factMap(): array
    {
        return [
            'question' => $this->question,
            'answer' => $this->answer,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function fieldKinds(): array
    {
        return [
            'question' => 'text',
            'answer' => 'multiline',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'question' => $this->question?->toArray(),
            'answer' => $this->answer?->toArray(),
            'source_urls' => $this->sourceUrls,
            'external_id' => $this->externalId,
            'fingerprint' => $this->fingerprint,
            'is_temporary_fingerprint' => $this->isTemporaryFingerprint,
        ];
    }

    public function with(
        ?ImportedFact $question = null,
        ?ImportedFact $answer = null,
        ?array $sourceUrls = null,
        ?string $externalId = null,
        ?string $fingerprint = null,
        ?bool $isTemporaryFingerprint = null,
    ): self {
        return new self(
            question: $question ?? $this->question,
            answer: $answer ?? $this->answer,
            sourceUrls: $sourceUrls ?? $this->sourceUrls,
            externalId: $externalId ?? $this->externalId,
            fingerprint: $fingerprint ?? $this->fingerprint,
            isTemporaryFingerprint: $isTemporaryFingerprint ?? $this->isTemporaryFingerprint,
        );
    }

    /**
     * Pure, data-only identity fingerprint. Returns null — insufficient data for a
     * stable identity — when the question is empty; OnboardingEntityDeduplicator
     * assigns a temporary, draft-scoped fingerprint in that case.
     */
    public function stableFingerprint(): ?string
    {
        $question = self::normalize($this->question?->value);

        if ($question === '') {
            return null;
        }

        return hash('sha256', $question);
    }

    private static function normalize(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
