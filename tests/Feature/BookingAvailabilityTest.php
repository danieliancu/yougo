<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Location;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\AvailabilityChecker;
use App\Services\Booking\BookingCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BookingAvailabilityTest extends TestCase
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

    public function test_rejects_past_date(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();

        $this->expectBookingError('Nu se pot face programari in trecut.');

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-26', '10:00');
    }

    public function test_rejects_invalid_location(): void
    {
        [$salon, , $service] = $this->appointmentSetup();

        $this->expectBookingError('Locatia nu apartine salonului.');

        $this->checker()->check($salon, 999, $service->id, '2026-04-28', '10:00');
    }

    public function test_rejects_invalid_service(): void
    {
        [$salon, $location] = $this->appointmentSetup();

        $this->expectBookingError('Serviciul nu apartine salonului.');

        $this->checker()->check($salon, $location->id, 999, '2026-04-28', '10:00');
    }

    public function test_rejects_service_not_available_at_selected_location(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['location_ids' => [12345]]);

        $this->expectBookingError("Serviciul {$service->name} nu este disponibil la locatia {$location->name}.");

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:00');
    }

    public function test_rejects_invalid_time_format(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();

        $this->expectBookingError('Formatul orei este invalid. Foloseste HH:MM.');

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:00:00');
    }

    public function test_rejects_booking_outside_opening_hours(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();

        $this->expectBookingError('Ora 08:30 este in afara programului locatiei (09:00 - 17:00).');

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '08:30');
    }

    public function test_rejects_booking_ending_after_closing_time(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['duration' => 60]);

        $this->expectBookingError('Programarea se termina la 17:30, dupa ora de inchidere a locatiei (17:00).');

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '16:30');
    }

    public function test_rejects_invalid_configured_location_hours_with_clear_message(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $location->update(['hours' => ['tue' => '80:00-20:00']]);

        $this->expectBookingError('Programul locatiei Central este configurat invalid (80:00-20:00). Orele trebuie sa fie intre 00:00 si 23:59.');

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '14:00');
    }

    public function test_allows_location_hours_with_en_dash_separator(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $location->update(['hours' => ['tue' => '09:00 – 17:00']]);

        $result = $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '14:00');

        $this->assertSame($location->id, $result[0]->id);
    }

    public function test_rejects_overlapping_booking(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['duration' => 60]);
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'confirmed');

        $this->expectBookingError('Intervalul 10:30 - 11:30 se suprapune cu o programare existenta.');

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:30');
    }

    public function test_allows_valid_booking_slot(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['duration' => 60]);
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'confirmed');

        $result = $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '11:00');

        $this->assertSame($location->id, $result[0]->id);
        $this->assertSame($service->id, $result[1]->id);
        $this->assertSame('2026-04-28', $result[2]->format('Y-m-d'));
    }

    public function test_booking_creator_creates_pending_booking_when_valid(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();

        $booking = app(BookingCreator::class)->createFromAiFunctionCall($salon, [
            'client_name' => 'Ana Pop',
            'client_phone' => '0700000000',
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'date' => '2026-04-28',
            'time' => '10:00',
        ]);

        $this->assertSame('pending', $booking->status);
        $this->assertSame($location->id, $booking->location_id);
        $this->assertSame($service->id, $booking->service_id);
        $this->assertSame('Ana Pop', $booking->client_name);
    }

    public function test_booking_creator_normalizes_ai_hour_to_hh_mm(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();

        $booking = app(BookingCreator::class)->createFromAiFunctionCall($salon, [
            'client_name' => 'Vasile',
            'client_phone' => '89787666',
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'date' => '2026-04-28',
            'time' => '12',
        ]);

        $this->assertSame('12:00', $booking->time);
    }

    public function test_booking_creator_normalizes_common_ai_time_variants(): void
    {
        $variants = [
            '12:00.' => '12:00',
            'ora 12' => '12:00',
            '12h' => '12:00',
            '12h30' => '12:30',
            '9.30' => '09:30',
            '12:00:00' => '12:00',
            'la ora 12:00 in Nordului' => '12:00',
            '8 si un sfert' => '08:15',
            'ora 8 si un sfert' => '08:15',
            '8 și un sfert' => '08:15',
            '8 si 15' => '08:15',
            'ora 14 si 98 767 65765' => '14:00',
            '8 si jumatate' => '08:30',
            '9 fara un sfert' => '08:45',
        ];

        foreach ($variants as $input => $expected) {
            [$salon, $location, $service] = $this->appointmentSetup();
            $location->update(['hours' => ['tue' => '08:00 - 17:00']]);

            $booking = app(BookingCreator::class)->createFromAiFunctionCall($salon, [
                'client_name' => 'Vasile',
                'client_phone' => '89787666',
                'location_id' => (string) $location->id,
                'service_id' => (string) $service->id,
                'date' => '2026-04-28',
                'time' => $input,
            ]);

            $this->assertSame($expected, $booking->time, "Failed normalizing {$input}");
        }
    }

    private function appointmentSetup(array $serviceOverrides = []): array
    {
        $user = User::factory()->create();
        $salon = Salon::query()->create([
            'user_id' => $user->id,
            'name' => 'YouGo Studio',
        ]);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => [
                'mon' => '09:00 - 17:00',
                'tue' => '09:00 - 17:00',
                'wed' => 'Inchis',
            ],
        ]);
        $service = $salon->services()->create(array_merge([
            'name' => 'Consultatie',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ], $serviceOverrides));

        return [$salon, $location, $service];
    }

    private function createBooking(Salon $salon, Location $location, Service $service, string $date, string $time, string $status): Booking
    {
        return $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Client existent',
            'client_phone' => '0700000001',
            'date' => $date,
            'time' => $time,
            'status' => $status,
        ]);
    }

    private function checker(): AvailabilityChecker
    {
        return app(AvailabilityChecker::class);
    }

    private function expectBookingError(string $message): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage($message);
    }
}
