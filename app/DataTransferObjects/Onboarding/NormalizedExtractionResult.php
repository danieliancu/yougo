<?php

namespace App\DataTransferObjects\Onboarding;

use App\Exceptions\Onboarding\InvalidExtractionResultException;

/**
 * The single validated shape that "normalized_extraction_result" is allowed to be.
 * fromArray() is the only gate: any analyzer output must pass through it before being
 * treated as normalized. Nothing else in the codebase is allowed to skip this.
 */
final readonly class NormalizedExtractionResult
{
    public const CURRENT_SCHEMA_VERSION = '1.0';

    /**
     * @param  list<LocationData>  $locations
     * @param  list<ServiceData>  $services
     * @param  list<StaffData>  $staff
     * @param  list<FaqEntryData>  $faq
     * @param  list<PolicyEntryData>  $policies
     */
    public function __construct(
        public string $schemaVersion,
        public ?BusinessProfileData $business,
        public ?ContactData $contact,
        public array $locations = [],
        public array $services = [],
        public array $staff = [],
        public array $faq = [],
        public array $policies = [],
    ) {}

    public static function fromArray(array $raw): self
    {
        if (! isset($raw['schema_version']) || ! is_string($raw['schema_version']) || $raw['schema_version'] === '') {
            throw new InvalidExtractionResultException('normalized extraction result is missing a schema_version.');
        }

        return new self(
            schemaVersion: $raw['schema_version'],
            business: isset($raw['business']) ? BusinessProfileData::fromArray(self::asArray($raw['business'], 'business')) : null,
            contact: isset($raw['contact']) ? ContactData::fromArray(self::asArray($raw['contact'], 'contact')) : null,
            locations: self::parseList($raw['locations'] ?? [], 'locations', LocationData::class),
            services: self::parseList($raw['services'] ?? [], 'services', ServiceData::class),
            staff: self::parseList($raw['staff'] ?? [], 'staff', StaffData::class),
            faq: self::parseList($raw['faq'] ?? [], 'faq', FaqEntryData::class),
            policies: self::parseList($raw['policies'] ?? [], 'policies', PolicyEntryData::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'business' => $this->business?->toArray(),
            'contact' => $this->contact?->toArray(),
            'locations' => array_map(static fn (LocationData $item) => $item->toArray(), $this->locations),
            'services' => array_map(static fn (ServiceData $item) => $item->toArray(), $this->services),
            'staff' => array_map(static fn (StaffData $item) => $item->toArray(), $this->staff),
            'faq' => array_map(static fn (FaqEntryData $item) => $item->toArray(), $this->faq),
            'policies' => array_map(static fn (PolicyEntryData $item) => $item->toArray(), $this->policies),
        ];
    }

    /**
     * @param  list<LocationData>  $locations
     * @param  list<ServiceData>  $services
     */
    public function withEntities(array $locations, array $services): self
    {
        return new self(
            $this->schemaVersion,
            $this->business,
            $this->contact,
            $locations,
            $services,
            $this->staff,
            $this->faq,
            $this->policies,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new InvalidExtractionResultException("normalized extraction result field [{$field}] must be an array.");
        }

        return $value;
    }

    /**
     * @return list<object>
     */
    private static function parseList(mixed $list, string $field, string $class): array
    {
        if (! is_array($list)) {
            throw new InvalidExtractionResultException("normalized extraction result field [{$field}] must be an array.");
        }

        return array_values(array_map(
            static function ($item) use ($class, $field) {
                if (! is_array($item)) {
                    throw new InvalidExtractionResultException("normalized extraction result field [{$field}] entries must be arrays.");
                }

                return $class::fromArray($item);
            },
            $list
        ));
    }
}
