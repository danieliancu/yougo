<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Business\IndustryDefaultsService;
use App\Services\Business\IndustryMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndustryDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_salon_beauty_recommends_appointment_primary(): void
    {
        $recommendation = app(IndustryDefaultsService::class)->recommendationFor('salon-beauty');

        $this->assertSame('appointment', $recommendation['primary']);
        $this->assertContains('request', $recommendation['secondary']);
    }

    public function test_home_services_recommends_request_primary(): void
    {
        $recommendation = app(IndustryDefaultsService::class)->recommendationFor('home-services');

        $this->assertSame('request', $recommendation['primary']);
        $this->assertContains('appointment', $recommendation['secondary']);
    }

    public function test_auto_service_recommends_request_primary_with_appointment_secondary(): void
    {
        $recommendation = app(IndustryDefaultsService::class)->recommendationFor('auto-service');

        $this->assertSame('request', $recommendation['primary']);
        $this->assertSame(['appointment'], $recommendation['secondary']);
    }

    public function test_industry_level_override_wins_over_business_type_default(): void
    {
        $businessTypeLevel = app(IndustryDefaultsService::class)->recommendationFor('restaurant');
        $this->assertSame('request', $businessTypeLevel['primary']);

        $industryLevel = app(IndustryDefaultsService::class)->recommendationFor('restaurant', 'catering');
        $this->assertSame('appointment', $industryLevel['primary']);
        $this->assertContains('request', $industryLevel['secondary']);
    }

    public function test_detected_industry_does_not_override_selected_until_confirmed(): void
    {
        $salon = $this->createSalon(['business_type' => 'salon-beauty']);

        app(IndustryDefaultsService::class)->applyRecommendation($salon, 'auto-service');
        $salon->refresh();

        $this->assertSame('request', $salon->recommended_primary_capability);
        $this->assertSame('appointment', $salon->primaryCapability());
        $this->assertSame('salon-beauty', $salon->business_type);
    }

    public function test_reapplying_recommendation_is_idempotent(): void
    {
        $salon = $this->createSalon();
        $service = app(IndustryDefaultsService::class);

        $service->applyRecommendation($salon, 'auto-service');
        $first = $salon->refresh()->recommended_primary_capability;

        $service->applyRecommendation($salon, 'auto-service');
        $second = $salon->refresh()->recommended_primary_capability;

        $this->assertSame($first, $second);
        $this->assertSame('request', $second);
    }

    public function test_confirming_recommendation_activates_it(): void
    {
        $salon = $this->createSalon();
        $service = app(IndustryDefaultsService::class);

        $service->applyRecommendation($salon, 'auto-service');
        $salon->refresh();

        $service->confirm($salon, $salon->recommended_primary_capability, $salon->recommended_secondary_capabilities, false);
        $salon->refresh();

        $this->assertSame('request', $salon->primaryCapability());
        $this->assertContains('appointment', $salon->enabledCapabilities());
        $this->assertSame('confirmed', $salon->capabilities_source);
    }

    public function test_customized_confirmation_is_marked_custom_not_confirmed(): void
    {
        $salon = $this->createSalon();
        $service = app(IndustryDefaultsService::class);

        $service->confirm($salon, 'request', ['request', 'appointment'], true);
        $salon->refresh();

        $this->assertSame('custom', $salon->capabilities_source);
    }

    public function test_confirmed_customization_is_not_overwritten_by_a_later_recommendation(): void
    {
        $salon = $this->createSalon();
        $service = app(IndustryDefaultsService::class);

        $service->confirm($salon, 'request', ['request'], true);
        $this->assertTrue($salon->isCapabilitiesLocked());

        // A later industry re-detection updates the *recommended* fields only.
        $service->applyRecommendation($salon, 'salon-beauty');
        $salon->refresh();

        $this->assertSame('appointment', $salon->recommended_primary_capability);
        $this->assertSame('request', $salon->primaryCapability());
        $this->assertSame('custom', $salon->capabilities_source);
    }

    public function test_works_without_a_website_from_manual_business_type_selection(): void
    {
        $salon = $this->createSalon(['business_type' => 'home-services']);

        $recommendation = app(IndustryDefaultsService::class)->recommendationFor($salon->business_type);
        app(IndustryDefaultsService::class)->applyRecommendation($salon, $salon->business_type);
        $salon->refresh();

        $this->assertSame('request', $recommendation['primary']);
        $this->assertSame('request', $salon->recommended_primary_capability);
    }

    public function test_industry_matcher_finds_alias(): void
    {
        $result = app(IndustryMatcher::class)->match('instalator');

        $this->assertTrue($result['matched']);
        $this->assertSame('home-services', $result['business_type']);
    }

    public function test_industry_matcher_reports_uncertain_for_unknown_text(): void
    {
        $result = app(IndustryMatcher::class)->match('Coafor & Servicii de Frumusete Deosebite');

        $this->assertFalse($result['matched']);
        $this->assertNull($result['business_type']);
    }

    public function test_industry_matcher_reports_uncertain_for_empty_text(): void
    {
        $result = app(IndustryMatcher::class)->match(null);

        $this->assertFalse($result['matched']);
    }

    private function createSalon(array $attributes = []): Salon
    {
        $user = User::factory()->create();

        return Salon::query()->create(array_merge([
            'user_id' => $user->id,
            'name' => 'YouGo Studio',
        ], $attributes));
    }
}
