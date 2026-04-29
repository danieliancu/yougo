<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Assistant\GeminiPayloadBuilder;
use App\Services\Booking\BookingCreator;
use App\Services\Modes\Appointment\AppointmentRequiredFieldsResolver;
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
            'business_type' => 'auto-service',
            'ai_industry_categories' => ['mot-inspection', 'tyres', 'car-diagnostics'],
            'ai_main_focus' => 'mot-inspection',
            'ai_custom_context' => ['flote comerciale', 'urgente'],
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Hello'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('mod business: appointment', $instruction);
        $this->assertStringContainsString('tip business: auto-service', $instruction);
        $this->assertStringContainsString('categories: MOT / inspection, Tyres, Car diagnostics', $instruction);
        $this->assertStringContainsString('custom context: flote comerciale, urgente', $instruction);
        $this->assertStringContainsString('Main focus: MOT / inspection', $instruction);
        $this->assertStringContainsString('Services configured in the dashboard remain the source of truth', $instruction);
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

    public function test_appointment_mode_includes_appointment_specific_prompt_context(): void
    {
        $salon = $this->createSalon([
            'mode' => Salon::MODE_APPOINTMENT,
            'booking_confirmations' => true,
        ]);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['mon' => '09:00 - 17:00'],
        ]);
        $service = $salon->services()->create([
            'name' => 'Consultatie',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
        $staff = $salon->staff()->create([
            'location_id' => $location->id,
            'name' => 'Ana',
            'role' => 'Medic',
            'active' => true,
        ]);
        $service->staffMembers()->attach($staff->id);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Book me'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Appointment mode este activ', $instruction);
        $this->assertStringContainsString('Locatii si orar: ID '.$location->id.': Central', $instruction);
        $this->assertStringContainsString('Servicii oferite: ID '.$service->id.': Consultatie', $instruction);
        $this->assertStringContainsString('Staff disponibil: ID '.$staff->id.': Ana', $instruction);
        $this->assertStringContainsString('foloseste staff_id doar daca acel ID este listat la serviciul selectat', $instruction);
        $this->assertStringContainsString('Nu inventa niciodata staff_id', $instruction);
        $this->assertStringContainsString('capacitate maxima simultana: implicit 1', $instruction);
        $this->assertStringContainsString('Nu ghici capacitatea', $instruction);
        $this->assertStringContainsString('foloseste checkAvailability', $instruction);
        $this->assertStringContainsString('preferred_time/after_time', $instruction);
        $this->assertStringContainsString('urmeaza noua ora ceruta de utilizator', $instruction);
        $this->assertStringContainsString('Inainte sa folosesti bookBooking, recapituleaza datele si cere confirmarea clientului', $instruction);
        $this->assertStringContainsString('Programarile create de AI raman pending si trebuie confirmate de echipa.', $instruction);
    }

    public function test_required_fields_come_from_ai_settings(): void
    {
        $salon = $this->createSalon([
            'mode' => Salon::MODE_APPOINTMENT,
            'ai_collect_phone' => false,
        ]);

        $required = app(AppointmentRequiredFieldsResolver::class)->resolve($salon);
        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Book me'],
        ]);
        $toolRequired = $payload['tools'][0]['functionDeclarations'][0]['parameters']['required'];
        $toolProperties = $payload['tools'][0]['functionDeclarations'][0]['parameters']['properties'];
        $availabilityTool = $payload['tools'][0]['functionDeclarations'][1];

        $this->assertSame(['client_name', 'service', 'location', 'date', 'time'], $required);
        $this->assertSame(['client_name', 'service_id', 'location_id', 'date', 'time'], $toolRequired);
        $this->assertArrayHasKey('staff_id', $toolProperties);
        $this->assertNotContains('staff_id', $toolRequired);
        $this->assertSame('checkAvailability', $availabilityTool['name']);
        $this->assertArrayHasKey('preferred_time', $availabilityTool['parameters']['properties']);
        $this->assertArrayHasKey('after_time', $availabilityTool['parameters']['properties']);
        $this->assertSame(['location_id', 'service_id', 'date'], $availabilityTool['parameters']['required']);
    }

    public function test_default_required_fields_work_when_settings_are_missing(): void
    {
        $required = app(AppointmentRequiredFieldsResolver::class)->resolve(new Salon);

        $this->assertSame([
            'client_name',
            'client_phone',
            'service',
            'location',
            'date',
            'time',
        ], $required);
    }

    public function test_booking_tool_is_hidden_when_booking_is_disabled(): void
    {
        $salon = $this->createSalon([
            'mode' => Salon::MODE_APPOINTMENT,
            'ai_booking_enabled' => false,
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Book me'],
        ]);

        $this->assertArrayNotHasKey('tools', $payload);
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
