<?php

namespace App\Services\Onboarding;

use App\Models\Salon;

class OnboardingChecklistService
{
    public function forSalon(Salon $salon): array
    {
        $salon->loadCount([
            'locations',
            'services',
            'staff',
            'conversations',
        ]);

        $steps = [
            $this->step(
                'business_profile',
                'onboardingBusinessProfile',
                'onboardingBusinessProfileDescription',
                '/dashboard/settings',
                filled($salon->name) && filled($salon->business_type),
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
                $this->hasAiConfiguration($salon),
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
                '/dashboard',
                false,
                false,
                true,
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

    private function hasAiConfiguration(Salon $salon): bool
    {
        return filled($salon->ai_assistant_name)
            || filled($salon->ai_business_summary)
            || filled($salon->ai_custom_instructions)
            || count($salon->ai_industry_categories ?? []) > 0
            || count($salon->ai_custom_context ?? []) > 0;
    }
}
