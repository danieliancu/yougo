<?php

namespace App\Services\Onboarding;

use App\DataTransferObjects\Onboarding\BusinessProfileData;
use App\DataTransferObjects\Onboarding\ContactData;
use App\DataTransferObjects\Onboarding\FaqEntryData;
use App\DataTransferObjects\Onboarding\LocationData;
use App\DataTransferObjects\Onboarding\PolicyEntryData;
use App\DataTransferObjects\Onboarding\ServiceData;
use App\DataTransferObjects\Onboarding\StaffData;

/**
 * The review UI's field list is generated from this, not hand-duplicated in the
 * frontend — each DTO's fieldKinds() is keyed identically to its factMap(), which is
 * what OnboardingDraftConfirmationService::collectMissingFactDecisions() iterates to
 * decide what needs a decision. Sending that same key set to the frontend makes it
 * structurally impossible for the review screen to omit a field the backend can require
 * a decision for (or to show one the backend doesn't know about).
 */
class OnboardingFieldSchema
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function forReview(): array
    {
        return [
            'business' => BusinessProfileData::fieldKinds(),
            'contact' => ContactData::fieldKinds(),
            'locations' => LocationData::fieldKinds(),
            'services' => ServiceData::fieldKinds(),
            'staff' => StaffData::fieldKinds(),
            'faq' => FaqEntryData::fieldKinds(),
            'policies' => PolicyEntryData::fieldKinds(),
        ];
    }
}
