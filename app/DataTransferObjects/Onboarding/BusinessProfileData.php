<?php

namespace App\DataTransferObjects\Onboarding;

use App\Services\Onboarding\OnboardingHoursValidator;

final readonly class BusinessProfileData
{
    public function __construct(
        public ?ImportedFact $name = null,
        public ?ImportedFact $businessType = null,
        public ?ImportedFact $description = null,
        public ?ImportedFact $website = null,
        public ?ImportedFact $languages = null,
        public ?ImportedFact $serviceAtCustomerLocation = null,
        public ?ImportedFact $openingHours = null,
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
            businessType: isset($raw['business_type']) ? ImportedFact::fromArray($raw['business_type']) : null,
            description: isset($raw['description']) ? ImportedFact::fromArray($raw['description']) : null,
            website: isset($raw['website']) ? ImportedFact::fromArray($raw['website']) : null,
            languages: isset($raw['languages']) ? ImportedFact::fromArray($raw['languages']) : null,
            serviceAtCustomerLocation: isset($raw['service_at_customer_location']) ? ImportedFact::fromArray($raw['service_at_customer_location']) : null,
            openingHours: $openingHours,
        );
    }

    /**
     * @return array<string, ?ImportedFact>
     */
    public function factMap(): array
    {
        return [
            'name' => $this->name,
            'business_type' => $this->businessType,
            'description' => $this->description,
            'website' => $this->website,
            'languages' => $this->languages,
            'service_at_customer_location' => $this->serviceAtCustomerLocation,
            'opening_hours' => $this->openingHours,
        ];
    }

    /**
     * The review UI's field list, straight from this factMap()'s key set — the single
     * source of truth for "what fields exist here", so the frontend can never drift out
     * of sync with it (see OnboardingFieldSchema).
     *
     * @return array<string, string>
     */
    public static function fieldKinds(): array
    {
        return [
            'name' => 'text',
            'business_type' => 'text',
            'description' => 'multiline',
            'website' => 'text',
            'languages' => 'list',
            'service_at_customer_location' => 'boolean',
            'opening_hours' => 'hours',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name?->toArray(),
            'business_type' => $this->businessType?->toArray(),
            'description' => $this->description?->toArray(),
            'website' => $this->website?->toArray(),
            'languages' => $this->languages?->toArray(),
            'service_at_customer_location' => $this->serviceAtCustomerLocation?->toArray(),
            'opening_hours' => $this->openingHours?->toArray(),
        ];
    }
}
