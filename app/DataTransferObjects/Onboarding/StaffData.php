<?php

namespace App\DataTransferObjects\Onboarding;

/**
 * Parsed and retained in the normalized result, but not written to the live `staff`
 * table in Task 1 — deferred to Task 2.
 */
final readonly class StaffData
{
    public function __construct(
        public ?ImportedFact $name = null,
        public ?ImportedFact $role = null,
        public ?ImportedFact $offeredServices = null,
        public ?ImportedFact $locationAssociations = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            name: isset($raw['name']) ? ImportedFact::fromArray($raw['name']) : null,
            role: isset($raw['role']) ? ImportedFact::fromArray($raw['role']) : null,
            offeredServices: isset($raw['offered_services']) ? ImportedFact::fromArray($raw['offered_services']) : null,
            locationAssociations: isset($raw['location_associations']) ? ImportedFact::fromArray($raw['location_associations']) : null,
        );
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
        ];
    }
}
