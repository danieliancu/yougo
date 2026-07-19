<?php

namespace App\DataTransferObjects\Onboarding;

use App\Services\Onboarding\OnboardingHoursValidator;
use Illuminate\Support\Str;

final readonly class LocationData
{
    /**
     * @param  list<string>  $sourceUrls  normalized, deduplicated pages this location was found on — provenance only, never part of identity
     */
    public function __construct(
        public ?ImportedFact $name = null,
        public ?ImportedFact $address = null,
        public ?ImportedFact $city = null,
        public ?ImportedFact $county = null,
        public ?ImportedFact $postcode = null,
        public ?ImportedFact $country = null,
        public ?ImportedFact $phone = null,
        public ?ImportedFact $openingHours = null,
        public array $sourceUrls = [],
        public ?string $externalId = null,
        public ?string $fingerprint = null,
        public bool $isTemporaryFingerprint = false,
    ) {}

    public static function fromArray(array $raw): self
    {
        $validator = new OnboardingHoursValidator;

        $openingHours = isset($raw['opening_hours']) ? ImportedFact::fromArray($raw['opening_hours']) : null;

        if ($openingHours !== null && is_array($openingHours->value)) {
            $openingHours = $openingHours->withValue($validator->validate($openingHours->value));
        }

        return new self(
            name: isset($raw['name']) ? ImportedFact::fromArray($raw['name']) : null,
            address: isset($raw['address']) ? ImportedFact::fromArray($raw['address']) : null,
            city: isset($raw['city']) ? ImportedFact::fromArray($raw['city']) : null,
            county: isset($raw['county']) ? ImportedFact::fromArray($raw['county']) : null,
            postcode: isset($raw['postcode']) ? ImportedFact::fromArray($raw['postcode']) : null,
            country: isset($raw['country']) ? ImportedFact::fromArray($raw['country']) : null,
            phone: isset($raw['phone']) ? ImportedFact::fromArray($raw['phone']) : null,
            openingHours: $openingHours,
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
            'address' => $this->address,
            'city' => $this->city,
            'county' => $this->county,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'phone' => $this->phone,
            'opening_hours' => $this->openingHours,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name?->toArray(),
            'address' => $this->address?->toArray(),
            'city' => $this->city?->toArray(),
            'county' => $this->county?->toArray(),
            'postcode' => $this->postcode?->toArray(),
            'country' => $this->country?->toArray(),
            'phone' => $this->phone?->toArray(),
            'opening_hours' => $this->openingHours?->toArray(),
            'source_urls' => $this->sourceUrls,
            'external_id' => $this->externalId,
            'fingerprint' => $this->fingerprint,
            'is_temporary_fingerprint' => $this->isTemporaryFingerprint,
        ];
    }

    public function with(
        ?ImportedFact $name = null,
        ?ImportedFact $address = null,
        ?ImportedFact $city = null,
        ?ImportedFact $county = null,
        ?ImportedFact $postcode = null,
        ?ImportedFact $country = null,
        ?ImportedFact $phone = null,
        ?ImportedFact $openingHours = null,
        ?array $sourceUrls = null,
        ?string $externalId = null,
        ?string $fingerprint = null,
        ?bool $isTemporaryFingerprint = null,
    ): self {
        return new self(
            name: $name ?? $this->name,
            address: $address ?? $this->address,
            city: $city ?? $this->city,
            county: $county ?? $this->county,
            postcode: $postcode ?? $this->postcode,
            country: $country ?? $this->country,
            phone: $phone ?? $this->phone,
            openingHours: $openingHours ?? $this->openingHours,
            sourceUrls: $sourceUrls ?? $this->sourceUrls,
            externalId: $externalId ?? $this->externalId,
            fingerprint: $fingerprint ?? $this->fingerprint,
            isTemporaryFingerprint: $isTemporaryFingerprint ?? $this->isTemporaryFingerprint,
        );
    }

    /**
     * Pure, data-only identity fingerprint — never includes the exact page URL, so the
     * same location found on multiple pages resolves to the same identity. Returns null
     * when there isn't enough data for a stable identity (name plus at least one of
     * address/phone); OnboardingEntityDeduplicator assigns a temporary, draft-scoped
     * fingerprint in that case — this method has no knowledge of drafts.
     */
    public function stableFingerprint(): ?string
    {
        $name = self::normalize($this->name?->value);
        $address = self::normalize($this->address?->value);
        $city = self::normalize($this->city?->value);
        $county = self::normalize($this->county?->value);
        $postcode = self::normalize($this->postcode?->value);
        $phone = self::normalizePhone($this->phone?->value);

        if ($name === '' || ($address === '' && $phone === '')) {
            return null;
        }

        return hash('sha256', implode('|', [$name, $address, $city, $county, $postcode, $phone]));
    }

    private static function normalize(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Str::of($value)->ascii()->lower()->squish()->toString();
    }

    private static function normalizePhone(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return preg_replace('/[^0-9+]/', '', $value) ?? '';
    }
}
