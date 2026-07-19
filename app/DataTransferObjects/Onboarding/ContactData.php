<?php

namespace App\DataTransferObjects\Onboarding;

final readonly class ContactData
{
    public function __construct(
        public ?ImportedFact $businessPhone = null,
        public ?ImportedFact $notificationEmail = null,
        public ?ImportedFact $socialLinks = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            businessPhone: isset($raw['business_phone']) ? ImportedFact::fromArray($raw['business_phone']) : null,
            notificationEmail: isset($raw['notification_email']) ? ImportedFact::fromArray($raw['notification_email']) : null,
            socialLinks: isset($raw['social_links']) ? ImportedFact::fromArray($raw['social_links']) : null,
        );
    }

    /**
     * @return array<string, ?ImportedFact>
     */
    public function factMap(): array
    {
        return [
            'business_phone' => $this->businessPhone,
            'notification_email' => $this->notificationEmail,
            'social_links' => $this->socialLinks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'business_phone' => $this->businessPhone?->toArray(),
            'notification_email' => $this->notificationEmail?->toArray(),
            'social_links' => $this->socialLinks?->toArray(),
        ];
    }
}
