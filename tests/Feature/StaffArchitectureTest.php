<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Assistant\GeminiPayloadBuilder;
use App\Services\Booking\BookingCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->assertStringContainsString('locatie: Central', $instruction);
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

        return [$salon, $location, $service];
    }
}
