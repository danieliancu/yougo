<?php

namespace App\Services\Onboarding;

use App\Models\Salon;

class OnboardingChecklistService
{
    public function __construct(
        private readonly OnboardingHoursValidator $hoursValidator = new OnboardingHoursValidator,
    ) {}

    public function forSalon(Salon $salon): array
    {
        $salon->loadCount([
            'locations',
            'services',
            'staff',
            'conversations',
        ]);
        $salon->loadMissing('locations:id,salon_id,hours');

        $steps = [
            $this->step(
                'business_profile',
                'onboardingBusinessProfile',
                'onboardingBusinessProfileDescription',
                '/dashboard/settings',
                $this->hasBusinessProfile($salon),
                true
            ),
            $this->step(
                'location',
                'onboardingLocation',
                'onboardingLocationDescription',
                '/dashboard/locations',
                $salon->locations_count >= 1,
                true
            ),
            $this->step(
                'notification_email',
                'onboardingNotificationEmail',
                'onboardingNotificationEmailDescription',
                '/dashboard/settings',
                filled($salon->notification_email),
                true
            ),
            $this->step(
                'opening_hours',
                'onboardingOpeningHours',
                'onboardingOpeningHoursDescription',
                '/dashboard/locations',
                $this->hasOpeningHours($salon),
                true
            ),
            $this->step(
                'service',
                'onboardingService',
                'onboardingServiceDescription',
                '/dashboard/services',
                $salon->services_count >= 1,
                true
            ),
            $this->step(
                'staff',
                'onboardingStaff',
                'onboardingStaffDescription',
                '/dashboard/staff',
                $salon->staff_count >= 1,
                false,
                true
            ),
            $this->step(
                'ai_assistant',
                'onboardingAiAssistant',
                'onboardingAiAssistantDescription',
                '/dashboard/ai-settings',
                (bool) $salon->ai_assistant_setup_completed,
                true
            ),
            $this->step(
                'test_assistant',
                'onboardingTestAssistant',
                'onboardingTestAssistantDescription',
                "/assistant/{$salon->id}",
                $salon->conversations_count >= 1,
                false,
                true
            ),
            $this->step(
                'capacity_rules',
                'onboardingCapacityRules',
                'onboardingCapacityRulesDescription',
                '/dashboard/services',
                $salon->locations()->whereNotNull('max_concurrent_bookings')->exists()
                    || $salon->services()->whereNotNull('max_concurrent_bookings')->exists(),
                false,
                true
            ),
            $this->step(
                'install_widget',
                'onboardingInstallWidget',
                'onboardingInstallWidgetDescription',
                '/dashboard/widget',
                (bool) $salon->widget_setup_completed,
                true
            ),
        ];

        $required = collect($steps)->filter(fn ($step) => $step['required']);
        $completedRequired = $required->where('completed', true)->count();
        $next = $required->firstWhere('completed', false)
            ?? collect($steps)->filter(fn ($step) => ! $step['completed'] && ! $step['coming_soon'])->first();

        return [
            'steps' => $steps,
            'progress' => $required->count() > 0 ? (int) round(($completedRequired / $required->count()) * 100) : 100,
            'completed_count' => $completedRequired,
            'total_required' => $required->count(),
            'can_complete' => $completedRequired === $required->count(),
            'next_step' => $next ?: null,
            'completed' => (bool) $salon->onboarding_completed,
            'skipped' => (bool) $salon->onboarding_skipped,
        ];
    }

    private function step(
        string $key,
        string $labelKey,
        string $descriptionKey,
        string $href,
        bool $completed,
        bool $required,
        bool $optional = false,
        bool $comingSoon = false
    ): array {
        return [
            'key' => $key,
            'label_key' => $labelKey,
            'description_key' => $descriptionKey,
            'href' => $href,
            'completed' => $completed,
            'required' => $required,
            'optional' => $optional,
            'coming_soon' => $comingSoon,
        ];
    }

    /**
     * The real "can this salon actually take bookings" gate for opening hours — moved
     * here from the import-confirm step, which no longer blocks on it (a business can
     * finish import without hours and add them later from /dashboard/locations; only
     * going live requires them).
     */
    private function hasOpeningHours(Salon $salon): bool
    {
        foreach ($salon->locations as $location) {
            if (is_array($location->hours) && $this->hoursValidator->hasAnyScheduledDay($location->hours)) {
                return true;
            }
        }

        if ($salon->service_at_customer_location
            && is_array($salon->opening_hours)
            && $this->hoursValidator->hasAnyScheduledDay($salon->opening_hours)) {
            return true;
        }

        return false;
    }

    /**
     * `website` is deliberately not required here — onboarding has a dedicated "no
     * website" manual path (OnboardingImportService::start() with source_type=manual),
     * so requiring it would make this step permanently unfinishable for any business
     * without a website.
     */
    private function hasBusinessProfile(Salon $salon): bool
    {
        return filled($salon->name)
            && filled($salon->business_type)
            && filled($salon->business_phone)
            && filled($salon->notification_email);
    }
}
