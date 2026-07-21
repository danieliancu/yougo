<?php

namespace App\DataTransferObjects\Onboarding;

use Illuminate\Support\Str;

final readonly class StaffData
{
    /**
     * @param  list<string>  $sourceUrls  normalized, deduplicated pages this staff member was found on — provenance only, never part of identity
     */
    public function __construct(
        public ?ImportedFact $name = null,
        public ?ImportedFact $role = null,
        public ?ImportedFact $offeredServices = null,
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
            role: isset($raw['role']) ? ImportedFact::fromArray($raw['role']) : null,
            offeredServices: isset($raw['offered_services']) ? ImportedFact::fromArray($raw['offered_services']) : null,
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
            'role' => $this->role,
            'offered_services' => $this->offeredServices,
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
            'role' => 'text',
            'offered_services' => 'list',
            'location_associations' => 'list',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name?->toArray(),
            'role' => $this->role?->toArray(),
            'offered_services' => $this->offeredServices?->toArray(),
            'location_associations' => $this->locationAssociations?->toArray(),
            'source_urls' => $this->sourceUrls,
            'external_id' => $this->externalId,
            'fingerprint' => $this->fingerprint,
            'is_temporary_fingerprint' => $this->isTemporaryFingerprint,
        ];
    }

    public function with(
        ?ImportedFact $name = null,
        ?ImportedFact $role = null,
        ?ImportedFact $offeredServices = null,
        ?ImportedFact $locationAssociations = null,
        ?array $sourceUrls = null,
        ?string $externalId = null,
        ?string $fingerprint = null,
        ?bool $isTemporaryFingerprint = null,
    ): self {
        return new self(
            name: $name ?? $this->name,
            role: $role ?? $this->role,
            offeredServices: $offeredServices ?? $this->offeredServices,
            locationAssociations: $locationAssociations ?? $this->locationAssociations,
            sourceUrls: $sourceUrls ?? $this->sourceUrls,
            externalId: $externalId ?? $this->externalId,
            fingerprint: $fingerprint ?? $this->fingerprint,
            isTemporaryFingerprint: $isTemporaryFingerprint ?? $this->isTemporaryFingerprint,
        );
    }

    /**
     * Pure, data-only identity fingerprint. Returns null — insufficient data for a
     * stable identity — when the name is empty; OnboardingEntityDeduplicator assigns a
     * temporary, draft-scoped fingerprint in that case.
     *
     * Deliberately name-only, not name+role: a dense page split into multiple AI calls
     * routinely yields the same staff member with a shorter or missing role in one
     * chunk and a fuller one in another (e.g. "Founder X" vs "Founder X, Trainer,
     * Colorist") — including role in the identity hash turned every such member into
     * two-plus separate entries in a real import. Role text conflicts are resolved by
     * ImportedFactMerger instead (kept as a conflict, requires_confirmation=true)
     * rather than by fragmenting identity.
     */
    public function stableFingerprint(): ?string
    {
        $name = self::normalize($this->name?->value);

        if ($name === '') {
            return null;
        }

        return hash('sha256', $name);
    }

    private static function normalize(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
