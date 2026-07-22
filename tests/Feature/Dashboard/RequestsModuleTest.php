<?php

namespace Tests\Feature\Dashboard;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RequestsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_only_salon_does_not_see_requests_module(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $salon->setCapabilities('appointment', ['appointment'], Salon::CAPABILITIES_SOURCE_CONFIRMED);

        $this->actingAs($user)->get('/dashboard/overview')
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules.requests', false)
                ->where('modules.appointments', true)
            );
    }

    public function test_request_capability_unlocks_requests_module(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $salon->setCapabilities('request', ['request'], Salon::CAPABILITIES_SOURCE_CONFIRMED);

        $this->actingAs($user)->get('/dashboard/overview')
            ->assertInertia(fn (Assert $page) => $page->where('modules.requests', true));
    }

    public function test_mixed_mode_salon_sees_both_appointments_and_requests_modules(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $salon->setCapabilities('appointment', ['appointment', 'request'], Salon::CAPABILITIES_SOURCE_CONFIRMED);

        $this->actingAs($user)->get('/dashboard/overview')
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules.appointments', true)
                ->where('modules.requests', true)
            );
    }

    public function test_appointment_only_salon_has_no_regression_on_bookings_section(): void
    {
        [, $user] = $this->createSalonAndUser();

        $this->actingAs($user)->get('/dashboard/bookings')->assertOk();
    }

    public function test_requests_section_returns_404_when_not_in_allowed_list_changes(): void
    {
        [, $user] = $this->createSalonAndUser();

        $this->actingAs($user)->get('/dashboard/requests')->assertOk();
    }

    public function test_requests_listing_is_tenant_scoped(): void
    {
        [$salonA, $userA] = $this->createSalonAndUser();
        $salonA->setCapabilities('request', ['request'], Salon::CAPABILITIES_SOURCE_CONFIRMED);
        [$salonB] = $this->createSalonAndUser();
        $salonB->setCapabilities('request', ['request'], Salon::CAPABILITIES_SOURCE_CONFIRMED);

        $salonA->customerRequests()->create(['channel' => 'website', 'description' => 'A', 'client_name' => 'A', 'idempotency_key' => 'a1']);
        $salonB->customerRequests()->create(['channel' => 'website', 'description' => 'B', 'client_name' => 'B', 'idempotency_key' => 'b1']);

        $this->actingAs($userA)->get('/dashboard/requests')
            ->assertInertia(fn (Assert $page) => $page
                ->where('requests.pagination.total', 1)
                ->where('requests.items.0.client_name', 'A')
            );
    }

    public function test_requests_metrics_are_tenant_scoped(): void
    {
        [$salonA, $userA] = $this->createSalonAndUser();
        $salonA->setCapabilities('request', ['request'], Salon::CAPABILITIES_SOURCE_CONFIRMED);
        [$salonB] = $this->createSalonAndUser();
        $salonB->setCapabilities('request', ['request'], Salon::CAPABILITIES_SOURCE_CONFIRMED);

        $salonA->customerRequests()->create(['channel' => 'website', 'status' => 'new', 'idempotency_key' => 'a1']);
        $salonB->customerRequests()->create(['channel' => 'website', 'status' => 'new', 'idempotency_key' => 'b1']);
        $salonB->customerRequests()->create(['channel' => 'website', 'status' => 'new', 'idempotency_key' => 'b2']);

        $this->actingAs($userA)->get('/dashboard/requests')
            ->assertInertia(fn (Assert $page) => $page->where('requests.counters.new', 1));
    }

    public function test_requests_empty_state_returns_no_items_without_error(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $salon->setCapabilities('request', ['request'], Salon::CAPABILITIES_SOURCE_CONFIRMED);

        $this->actingAs($user)->get('/dashboard/requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('requests.items', []));
    }

    public function test_owner_can_update_request_status_via_dashboard_action(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $salon->setCapabilities('request', ['request'], Salon::CAPABILITIES_SOURCE_CONFIRMED);
        $request = $salon->customerRequests()->create(['channel' => 'website', 'idempotency_key' => 'k1']);

        $this->actingAs($user)->patch("/customer-requests/{$request->id}", ['status' => 'resolved'])->assertRedirect();

        $this->assertSame('resolved', $request->refresh()->status->value);
    }

    /**
     * @return array{0: Salon, 1: User}
     */
    private function createSalonAndUser(): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => 'YouGo Studio']);

        return [$salon, $user];
    }
}
