<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Assistant\GeminiPayloadBuilder;
use App\Services\Booking\BookingCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 4, 27, 9, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_salon_has_staff(): void
    {
        [$salon] = $this->salonSetup();

        $staff = $salon->staff()->create(['name' => 'Ana Pop']);

        $this->assertTrue($salon->staff()->whereKey($staff->id)->exists());
    }

    public function test_staff_can_belong_to_a_location(): void
    {
        [$salon, $location] = $this->salonSetup();

        $staff = $salon->staff()->create([
            'location_id' => $location->id,
            'name' => 'Ana Pop',
        ]);

        $this->assertSame($location->id, $staff->location->id);
    }

    public function test_service_can_be_linked_to_staff(): void
    {
        [$salon, , $service] = $this->salonSetup();
        $staff = $salon->staff()->create(['name' => 'Ana Pop']);

        $service->staffMembers()->attach($staff);

        $this->assertTrue($service->staffMembers()->whereKey($staff->id)->exists());
        $this->assertTrue($staff->services()->whereKey($service->id)->exists());
    }

    public function test_authenticated_user_can_create_staff(): void
    {
        [$salon, $location, $service, $user] = $this->salonSetup();
        $service->update(['staff' => ['Legacy Staff']]);

        $response = $this->actingAs($user)->post('/staff', [
            'name' => 'Ana Pop',
            'role' => 'Stylist',
            'email' => 'ana@example.com',
            'phone' => '0700000000',
            'location_id' => $location->id,
            'active' => true,
            'service_ids' => [$service->id],
        ]);

        $response->assertRedirect();
        $staff = $salon->staff()->firstOrFail();

        $this->assertSame('Ana Pop', $staff->name);
        $this->assertSame($salon->id, $staff->salon_id);
        $this->assertSame($location->id, $staff->location_id);
        $this->assertTrue($staff->locations()->whereKey($location->id)->exists());
        $this->assertTrue($staff->services()->whereKey($service->id)->exists());
        $this->assertSame(['Legacy Staff'], $service->refresh()->staff);
    }

    public function test_authenticated_user_can_create_staff_with_multiple_locations(): void
    {
        [$salon, $location, $service, $user] = $this->salonSetup();
        $secondLocation = $salon->locations()->create([
            'name' => 'Nord',
            'address' => 'Second Street',
        ]);

        $response = $this->actingAs($user)->post('/staff', [
            'name' => 'Ana Pop',
            'location_ids' => [$location->id, $secondLocation->id],
            'active' => true,
            'service_ids' => [$service->id],
        ]);

        $response->assertRedirect();
        $staff = $salon->staff()->firstOrFail();

        $this->assertSame($location->id, $staff->location_id);
        $this->assertEqualsCanonicalizing(
            [$location->id, $secondLocation->id],
            $staff->locations()->pluck('locations.id')->all()
        );
    }

    public function test_user_cannot_create_staff_with_location_from_another_salon(): void
    {
        [, , $service, $user] = $this->salonSetup();
        [, $otherLocation] = $this->salonSetup();

        $response = $this->actingAs($user)->from('/dashboard/staff')->post('/staff', [
            'name' => 'Ana Pop',
            'location_id' => $otherLocation->id,
            'active' => true,
            'service_ids' => [$service->id],
        ]);

        $response->assertRedirect('/dashboard/staff');
        $response->assertSessionHasErrors('location_id');
    }

    public function test_user_cannot_create_staff_with_location_ids_from_another_salon(): void
    {
        [, , $service, $user] = $this->salonSetup();
        [, $otherLocation] = $this->salonSetup();

        $response = $this->actingAs($user)->from('/dashboard/staff')->post('/staff', [
            'name' => 'Ana Pop',
            'location_ids' => [$otherLocation->id],
            'active' => true,
            'service_ids' => [$service->id],
        ]);

        $response->assertRedirect('/dashboard/staff');
        $response->assertSessionHasErrors('location_ids');
    }

    public function test_user_cannot_attach_services_from_another_salon(): void
    {
        [$salon, $location, , $user] = $this->salonSetup();
        [, , $otherService] = $this->salonSetup();

        $response = $this->actingAs($user)->from('/dashboard/staff')->post('/staff', [
            'name' => 'Ana Pop',
            'location_id' => $location->id,
            'active' => true,
            'service_ids' => [$otherService->id],
        ]);

        $response->assertRedirect('/dashboard/staff');
        $response->assertSessionHasErrors('service_ids');
        $this->assertFalse($salon->staff()->exists());
    }

    public function test_authenticated_user_can_update_staff(): void
    {
        [$salon, $location, $service, $user] = $this->salonSetup();
        $staff = $salon->staff()->create(['name' => 'Ana Pop']);

        $response = $this->actingAs($user)->put("/staff/{$staff->id}", [
            'name' => 'Ana Maria',
            'role' => 'Senior stylist',
            'email' => 'ana@example.com',
            'phone' => '0711111111',
            'location_id' => $location->id,
            'active' => false,
            'service_ids' => [$service->id],
        ]);

        $response->assertRedirect();
        $staff->refresh();

        $this->assertSame('Ana Maria', $staff->name);
        $this->assertSame('Senior stylist', $staff->role);
        $this->assertFalse($staff->active);
        $this->assertTrue($staff->locations()->whereKey($location->id)->exists());
        $this->assertTrue($staff->services()->whereKey($service->id)->exists());
    }

    public function test_authenticated_user_can_update_staff_locations(): void
    {
        [$salon, $location, , $user] = $this->salonSetup();
        $secondLocation = $salon->locations()->create([
            'name' => 'Nord',
            'address' => 'Second Street',
        ]);
        $staff = $salon->staff()->create([
            'location_id' => $location->id,
            'name' => 'Ana Pop',
        ]);
        $staff->locations()->attach($location);

        $response = $this->actingAs($user)->put("/staff/{$staff->id}", [
            'name' => 'Ana Maria',
            'location_ids' => [$secondLocation->id],
            'active' => true,
            'service_ids' => [],
        ]);

        $response->assertRedirect();
        $staff->refresh();

        $this->assertSame($secondLocation->id, $staff->location_id);
        $this->assertEqualsCanonicalizing(
            [$secondLocation->id],
            $staff->locations()->pluck('locations.id')->all()
        );
    }

    public function test_authenticated_user_can_delete_staff(): void
    {
        [$salon, , , $user] = $this->salonSetup();
        $staff = $salon->staff()->create(['name' => 'Ana Pop']);

        $this->actingAs($user)->delete("/staff/{$staff->id}")->assertRedirect();

        $this->assertFalse($salon->staff()->whereKey($staff->id)->exists());
    }

    public function test_dashboard_staff_section_loads_with_relationships(): void
    {
        [$salon, $location, $service, $user] = $this->salonSetup();
        $staff = $salon->staff()->create([
            'location_id' => $location->id,
            'name' => 'Ana Pop',
        ]);
        $staff->locations()->attach($location);
        $staff->services()->attach($service);

        $this->actingAs($user)->get('/dashboard/staff')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('section', 'staff')
                ->where('salon.staff.0.location.id', $location->id)
                ->where('salon.staff.0.locations.0.id', $location->id)
                ->where('salon.staff.0.services.0.id', $service->id)
            );
    }

    public function test_dashboard_services_section_loads_staff_members_for_service_badges(): void
    {
        [$salon, , $service, $user] = $this->salonSetup();
        $staff = $salon->staff()->create(['name' => 'Ana Pop']);
        $service->staffMembers()->attach($staff);

        $this->actingAs($user)->get('/dashboard/services')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('section', 'services')
                ->where('salon.services.0.staff_members.0.id', $staff->id)
                ->where('salon.services.0.staff_members.0.name', 'Ana Pop')
            );
    }

    public function test_service_update_does_not_erase_legacy_staff_when_staff_is_omitted(): void
    {
        [$salon, $location, $service, $user] = $this->salonSetup([], [
            'staff' => ['Maria Legacy'],
        ]);

        $response = $this->actingAs($user)->put("/services/{$service->id}", [
            'name' => 'Consultatie premium',
            'type' => '',
            'price' => '150',
            'duration' => 45,
            'location_ids' => [$location->id],
            'notes' => '',
        ]);

        $response->assertRedirect();

        $service->refresh();
        $this->assertSame('Consultatie premium', $service->name);
        $this->assertSame(['Maria Legacy'], $service->staff);
    }

    public function test_service_create_still_works_without_staff(): void
    {
        [$salon, $location, , $user] = $this->salonSetup();

        $response = $this->actingAs($user)->post('/services', [
            'name' => 'New Service',
            'type' => '',
            'price' => '200',
            'duration' => 60,
            'location_ids' => [$location->id],
            'notes' => '',
        ]);

        $response->assertRedirect();

        $service = $salon->services()->where('name', 'New Service')->firstOrFail();
        $this->assertSame([], $service->staff ?? []);
    }

    public function test_gemini_payload_includes_new_staff_data_when_available(): void
    {
        [$salon, $location, $service] = $this->salonSetup();
        $staff = $salon->staff()->create([
            'location_id' => $location->id,
            'name' => 'Ana Pop',
            'role' => 'Senior stylist',
            'email' => 'ana@example.com',
            'phone' => '0700000000',
        ]);
        $service->staffMembers()->attach($staff);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Who can help?'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString("ID {$staff->id}: Ana Pop", $instruction);
        $this->assertStringContainsString('rol: Senior stylist', $instruction);
        $this->assertStringContainsString('locatii: Central', $instruction);
        $this->assertStringContainsString("staff: ID {$staff->id}: Ana Pop / rol: Senior stylist", $instruction);
    }

    public function test_gemini_payload_falls_back_to_old_json_staff_when_no_staff_records_exist(): void
    {
        [$salon, , $service] = $this->salonSetup([
            'service_staff' => ['Maria Global'],
        ], [
            'staff' => ['Maria Service'],
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Who can help?'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Staff disponibil: Maria Global.', $instruction);
        $this->assertStringContainsString('staff: Maria Service', $instruction);
        $this->assertFalse($salon->staff()->exists());
        $this->assertSame(['Maria Service'], $service->staff);
    }

    public function test_existing_booking_creation_still_works_without_staff_id(): void
    {
        [$salon, $location, $service] = $this->salonSetup();

        $booking = app(BookingCreator::class)->createFromAiFunctionCall($salon, [
            'client_name' => 'Client Nou',
            'client_phone' => '0700000000',
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'date' => '2026-04-28',
            'time' => '10:00',
        ]);

        $this->assertSame('pending', $booking->status);
        $this->assertSame([], $booking->staff);
    }

    private function salonSetup(array $salonOverrides = [], array $serviceOverrides = []): array
    {
        $user = User::factory()->create();
        $salon = Salon::query()->create(array_merge([
            'user_id' => $user->id,
            'name' => 'YouGo Studio',
        ], $salonOverrides));
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['tue' => '09:00 - 17:00'],
        ]);
        $service = $salon->services()->create(array_merge([
            'name' => 'Consultatie',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ], $serviceOverrides));

        return [$salon, $location, $service, $user];
    }
}
