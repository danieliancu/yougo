<?php

namespace App\DataTransferObjects\Onboarding;

use Illuminate\Support\Str;

final readonly class PolicyEntryData
{
    /**
     * @param  list<string>  $sourceUrls  normalized, deduplicated pages this policy entry was found on — provenance only, never part of identity
     */
    public function __construct(
        public ?ImportedFact $title = null,
        public ?ImportedFact $content = null,
        public array $sourceUrls = [],
        public ?string $externalId = null,
        public ?string $fingerprint = null,
        public bool $isTemporaryFingerprint = false,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            title: isset($raw['title']) ? ImportedFact::fromArray($raw['title']) : null,
            content: isset($raw['content']) ? ImportedFact::fromArray($raw['content']) : null,
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
            'title' => $this->title,
            'content' => $this->content,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function fieldKinds(): array
    {
        return [
            'title' => 'text',
            'content' => 'multiline',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title?->toArray(),
            'content' => $this->content?->toArray(),
            'source_urls' => $this->sourceUrls,
            'external_id' => $this->externalId,
            'fingerprint' => $this->fingerprint,
            'is_temporary_fingerprint' => $this->isTemporaryFingerprint,
        ];
    }

    public function with(
        ?ImportedFact $title = null,
        ?ImportedFact $content = null,
        ?array $sourceUrls = null,
        ?string $externalId = null,
        ?string $fingerprint = null,
        ?bool $isTemporaryFingerprint = null,
    ): self {
        return new self(
            title: $title ?? $this->title,
            content: $content ?? $this->content,
            sourceUrls: $sourceUrls ?? $this->sourceUrls,
            externalId: $externalId ?? $this->externalId,
            fingerprint: $fingerprint ?? $this->fingerprint,
            isTemporaryFingerprint: $isTemporaryFingerprint ?? $this->isTemporaryFingerprint,
        );
    }

    /**
     * Pure, data-only identity fingerprint. Returns null — insufficient data for a
     * stable identity — when the title is empty; OnboardingEntityDeduplicator assigns
     * a temporary, draft-scoped fingerprint in that case.
     */
    public function stableFingerprint(): ?string
    {
        $title = self::normalize($this->title?->value);

        if ($title === '') {
            return null;
        }

        return hash('sha256', $title);
    }

    private static function normalize(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
