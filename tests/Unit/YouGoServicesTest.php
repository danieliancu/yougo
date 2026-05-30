<?php

namespace Tests\Unit;

use App\Support\YouGoServices;
use RuntimeException;
use Tests\TestCase;

class YouGoServicesTest extends TestCase
{
    public function test_service_catalog_contains_required_services_with_valid_schema(): void
    {
        $services = collect(YouGoServices::all());

        $this->assertSame(['website_chat', 'whatsapp_ai', 'phone_ai'], $services->pluck('key')->all());

        foreach ($services as $service) {
            foreach (['key', 'title_key', 'subtitle_key', 'short_label_key', 'icon', 'implementation_status', 'category', 'entitlement_key', 'sort_order'] as $field) {
                $this->assertArrayHasKey($field, $service);
            }

            $this->assertContains($service['implementation_status'], [YouGoServices::STATUS_LIVE, YouGoServices::STATUS_PLANNED]);
        }

        $this->assertTrue(YouGoServices::isServiceLive('website_chat'));
        $this->assertTrue(YouGoServices::isServiceLive('whatsapp_ai'));
        $this->assertTrue(YouGoServices::isServicePlanned('phone_ai'));
    }

    public function test_plan_service_keys_are_canonical_and_sorted_services_resolve(): void
    {
        YouGoServices::validatePlans();

        $this->assertSame(['website_chat'], YouGoServices::planServiceKeys('free'));
        $this->assertSame(['website_chat'], YouGoServices::planServiceKeys('website_chat'));
        $this->assertSame(['website_chat', 'whatsapp_ai'], YouGoServices::planServiceKeys('chat_whatsapp'));

        foreach (['voice_starter', 'voice_growth', 'voice_pro'] as $planKey) {
            $this->assertSame(['website_chat', 'whatsapp_ai', 'phone_ai'], YouGoServices::planServiceKeys($planKey));
            $this->assertTrue(YouGoServices::planHasPhoneAi($planKey));
        }

        $this->assertTrue(YouGoServices::planHasWebsiteChat('free'));
        $this->assertTrue(YouGoServices::planHasWhatsappAi('chat_whatsapp'));
        $this->assertFalse(YouGoServices::planHasPhoneAi('chat_whatsapp'));
        $this->assertSame(['website_chat', 'whatsapp_ai', 'phone_ai'], collect(YouGoServices::servicesForPlan('voice_starter'))->pluck('key')->all());
    }

    public function test_unknown_plan_service_key_fails_with_clear_exception(): void
    {
        config(['yougo_plans.free.service_keys' => ['website_chat', 'not_real']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Plan [free] references unknown service [not_real].');

        YouGoServices::validatePlans();
    }

    public function test_service_entitlements_do_not_require_legacy_plan_fields(): void
    {
        config([
            'yougo_plans.free.widgets_enabled' => false,
            'yougo_plans.free.whatsapp_enabled' => true,
            'yougo_plans.free.phone_enabled' => true,
            'yougo_plans.free.channels' => [],
            'yougo_plans.free.features' => [],
        ]);

        $this->assertTrue(YouGoServices::planHasWebsiteChat('free'));
        $this->assertFalse(YouGoServices::planHasWhatsappAi('free'));
        $this->assertFalse(YouGoServices::planHasPhoneAi('free'));
        $this->assertSame(['website_chat'], collect(YouGoServices::servicesForPlan('free'))->pluck('key')->all());
    }
}
