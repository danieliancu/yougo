<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Assistant\GeminiPayloadBuilder;
use App\Services\Booking\BookingCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BusinessModeTest extends TestCase
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

    public function test_new_salons_default_to_appointment_mode(): void
    {
        $salon = $this->createSalon();

        $this->assertSame(Salon::MODE_APPOINTMENT, $salon->refresh()->mode);
        $this->assertFalse($salon->onboarding_completed);
    }

    public function test_salon_helper_methods_work(): void
    {
        $appointment = $this->createSalon(['mode' => Salon::MODE_APPOINTMENT]);
        $reservation = $this->createSalon(['mode' => Salon::MODE_RESERVATION]);
        $lead = $this->createSalon(['mode' => Salon::MODE_LEAD]);

        $this->assertTrue($appointment->isAppointmentBased());
        $this->assertFalse($appointment->isReservationBased());
        $this->assertTrue($reservation->isReservationBased());
        $this->assertTrue($lead->isLeadBased());
        $this->assertSame('YouGo Studio', $appointment->displayName());
        $this->assertSame('YouGo Studio', $appointment->businessLabel());
    }

    public function test_gemini_payload_includes_mode_and_business_type_context(): void
    {
        $salon = $this->createSalon([
            'mode' => Salon::MODE_APPOINTMENT,
            'industry' => 'Medical Clinic',
            'business_type' => 'clinic',
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Hello'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('industrie: Medical Clinic', $instruction);
        $this->assertStringContainsString('mod business: appointment', $instruction);
        $this->assertStringContainsString('tip business: clinic', $instruction);
        $this->assertStringContainsString('modul curent este appointment', $instruction);
    }

    public function test_booking_tools_are_available_for_appointment_mode(): void
    {
        $salon = $this->createSalon(['mode' => Salon::MODE_APPOINTMENT]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Book me'],
        ]);

        $this->assertArrayHasKey('tools', $payload);
    }

    public function test_booking_tools_are_not_exposed_for_non_appointment_mode(): void
    {
        $salon = $this->createSalon(['mode' => Salon::MODE_RESERVATION]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Book me'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertStringContainsString('Modul curent nu este appointment', $instruction);
    }

    public function test_existing_booking_flow_still_works(): void
    {
        $salon = $this->createSalon(['mode' => Salon::MODE_APPOINTMENT]);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['tue' => '09:00 - 17:00'],
        ]);
        $service = $salon->services()->create([
            'name' => 'Consultatie',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);

        $booking = app(BookingCreator::class)->createFromAiFunctionCall($salon, [
            'client_name' => 'Client Nou',
            'client_phone' => '0700000000',
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'date' => '2026-04-28',
            'time' => '10:00',
        ]);

        $this->assertSame('pending', $booking->status);
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
