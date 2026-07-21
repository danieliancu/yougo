<?php

namespace App\DataTransferObjects\Onboarding;

use Illuminate\Support\Str;

final readonly class ServiceData
{
    /**
     * @param  list<string>  $sourceUrls  normalized, deduplicated pages this service was found on — provenance only, never part of identity
     */
    public function __construct(
        public ?ImportedFact $name = null,
        public ?ImportedFact $category = null,
        public ?ImportedFact $description = null,
        public ?ImportedFact $price = null,
        public ?ImportedFact $currency = null,
        public ?ImportedFact $duration = null,
        public ?ImportedFact $locationAssociations = null,
        public array $sourceUrls = [],
        public ?string $externalId = null,
        public ?string $fingerprint = null,
        public bool $isTemporaryFingerprint = false,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            name: isset($raw['name']) ? ImportedFact::fromArray($raw['name']) : null,
            category: isset($raw['category']) ? ImportedFact::fromArray($raw['category']) : null,
            description: isset($raw['description']) ? ImportedFact::fromArray($raw['description']) : null,
            price: isset($raw['price']) ? ImportedFact::fromArray($raw['price']) : null,
            currency: isset($raw['currency']) ? ImportedFact::fromArray($raw['currency']) : null,
            duration: isset($raw['duration']) ? ImportedFact::fromArray($raw['duration']) : null,
            locationAssociations: isset($raw['location_associations']) ? ImportedFact::fromArray($raw['location_associations']) : null,
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
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'duration' => $this->duration,
            'location_associations' => $this->locationAssociations,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function fieldKinds(): array
    {
        return [
            'name' => 'text',
            'category' => 'text',
            'description' => 'multiline',
            'price' => 'price',
            'currency' => 'text',
            'duration' => 'number',
            'location_associations' => 'location_checklist',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name?->toArray(),
            'category' => $this->category?->toArray(),
            'description' => $this->description?->toArray(),
            'price' => $this->price?->toArray(),
            'currency' => $this->currency?->toArray(),
            'duration' => $this->duration?->toArray(),
            'location_associations' => $this->locationAssociations?->toArray(),
            'source_urls' => $this->sourceUrls,
            'external_id' => $this->externalId,
            'fingerprint' => $this->fingerprint,
            'is_temporary_fingerprint' => $this->isTemporaryFingerprint,
        ];
    }

    public function with(
        ?ImportedFact $name = null,
        ?ImportedFact $category = null,
        ?ImportedFact $description = null,
        ?ImportedFact $price = null,
        ?ImportedFact $currency = null,
        ?ImportedFact $duration = null,
        ?ImportedFact $locationAssociations = null,
        ?array $sourceUrls = null,
        ?string $externalId = null,
        ?string $fingerprint = null,
        ?bool $isTemporaryFingerprint = null,
    ): self {
        return new self(
            name: $name ?? $this->name,
            category: $category ?? $this->category,
            description: $description ?? $this->description,
            price: $price ?? $this->price,
            currency: $currency ?? $this->currency,
            duration: $duration ?? $this->duration,
            locationAssociations: $locationAssociations ?? $this->locationAssociations,
            sourceUrls: $sourceUrls ?? $this->sourceUrls,
            externalId: $externalId ?? $this->externalId,
            fingerprint: $fingerprint ?? $this->fingerprint,
            isTemporaryFingerprint: $isTemporaryFingerprint ?? $this->isTemporaryFingerprint,
        );
    }

    /**
     * Pure, data-only identity fingerprint. Price is deliberately excluded (it changes
     * independently of identity). Returns null — insufficient data for a stable identity —
     * when only a bare name is available; OnboardingEntityDeduplicator assigns a temporary,
     * draft-scoped fingerprint in that case.
     */
    public function stableFingerprint(): ?string
    {
        $name = self::normalize($this->name?->value);
        $category = self::normalize($this->category?->value);
        $location = self::normalizedLocationReference();
        $duration = $this->duration?->value !== null ? (string) $this->duration->value : '';

        if ($name === '' || ($category === '' && $location === '' && $duration === '')) {
            return null;
        }

        return hash('sha256', implode('|', [$name, $category, $location, $duration]));
    }

    /**
     * The name half of the identity, exposed for OnboardingEntityDeduplicator's
     * name-based consolidation pass — see that method for why.
     */
    public function normalizedNameKey(): string
    {
        return self::normalize($this->name?->value);
    }

    /**
     * The category/location/duration thirds of the identity, exposed for
     * OnboardingEntityDeduplicator's complementary-details consolidation pass — see that
     * method for why.
     */
    public function normalizedCategoryKey(): string
    {
        return self::normalize($this->category?->value);
    }

    public function normalizedLocationKey(): string
    {
        return self::normalizedLocationReference();
    }

    public function normalizedDurationKey(): string
    {
        return $this->duration?->value !== null ? (string) $this->duration->value : '';
    }

    private function normalizedLocationReference(): string
    {
        $value = $this->locationAssociations?->value;

        if (! is_array($value) || $value === []) {
            return '';
        }

        $normalized = array_map(static fn ($item) => self::normalize(is_string($item) ? $item : null), $value);
        sort($normalized);

        return implode(',', array_filter($normalized));
    }

    private static function normalize(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
