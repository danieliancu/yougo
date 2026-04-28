<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Location;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\AvailabilityChecker;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\AvailabilitySlotFinder;
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

        $this->expectBookingError('Locatia este complet ocupata in intervalul selectat.');

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:30');
    }

    public function test_location_and_service_store_capacity(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['max_concurrent_bookings' => 3]);
        $location->update(['max_concurrent_bookings' => 2]);

        $this->assertSame(2, $location->refresh()->max_concurrent_bookings);
        $this->assertSame(3, $service->refresh()->max_concurrent_bookings);
    }

    public function test_location_validation_accepts_nullable_and_valid_capacity(): void
    {
        [, , , $user] = $this->appointmentSetup();

        $this->actingAs($user)->post('/locations', [
            'name' => 'Nord',
            'address' => 'Second Street',
            'hours' => [],
            'max_concurrent_bookings' => null,
        ])->assertRedirect();

        $this->actingAs($user)->post('/locations', [
            'name' => 'Sud',
            'address' => 'Third Street',
            'hours' => [],
            'max_concurrent_bookings' => 4,
        ])->assertRedirect();

        $this->assertDatabaseHas('locations', ['name' => 'Sud', 'max_concurrent_bookings' => 4]);
    }

    public function test_location_validation_rejects_invalid_capacity(): void
    {
        [, , , $user] = $this->appointmentSetup();

        $this->actingAs($user)->from('/dashboard/locations')->post('/locations', [
            'name' => 'Nord',
            'address' => 'Second Street',
            'hours' => [],
            'max_concurrent_bookings' => 0,
        ])->assertSessionHasErrors('max_concurrent_bookings');

        $this->actingAs($user)->from('/dashboard/locations')->post('/locations', [
            'name' => 'Sud',
            'address' => 'Third Street',
            'hours' => [],
            'max_concurrent_bookings' => -1,
        ])->assertSessionHasErrors('max_concurrent_bookings');
    }

    public function test_service_validation_accepts_nullable_and_valid_capacity(): void
    {
        [, $location, , $user] = $this->appointmentSetup();

        $this->actingAs($user)->post('/services', [
            'name' => 'Masaj',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
            'max_concurrent_bookings' => null,
        ])->assertRedirect();

        $this->actingAs($user)->post('/services', [
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
            'max_concurrent_bookings' => 5,
        ])->assertRedirect();

        $this->assertDatabaseHas('services', ['name' => 'Tuns', 'max_concurrent_bookings' => 5]);
    }

    public function test_service_validation_rejects_invalid_capacity(): void
    {
        [, $location, , $user] = $this->appointmentSetup();

        $payload = [
            'name' => 'Masaj',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ];

        $this->actingAs($user)->from('/dashboard/services')->post('/services', $payload + [
            'max_concurrent_bookings' => 0,
        ])->assertSessionHasErrors('max_concurrent_bookings');

        $this->actingAs($user)->from('/dashboard/services')->post('/services', $payload + [
            'max_concurrent_bookings' => -1,
        ])->assertSessionHasErrors('max_concurrent_bookings');
    }

    public function test_default_location_capacity_is_one(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['max_concurrent_bookings' => 2]);
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'confirmed');

        $this->expectBookingError('Locatia este complet ocupata in intervalul selectat.');

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:15');
    }

    public function test_allows_overlap_when_location_capacity_allows_it(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['max_concurrent_bookings' => 2]);
        $location->update(['max_concurrent_bookings' => 2]);
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'confirmed');

        $result = $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:15');

        $this->assertSame($location->id, $result[0]->id);
    }

    public function test_default_service_capacity_is_one(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $location->update(['max_concurrent_bookings' => 2]);
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'confirmed');

        $this->expectBookingError('Serviciul este complet ocupat in intervalul selectat.');

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:15');
    }

    public function test_allows_overlap_when_service_capacity_allows_it(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['max_concurrent_bookings' => 2]);
        $location->update(['max_concurrent_bookings' => 2]);
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'confirmed');

        $result = $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:15');

        $this->assertSame($service->id, $result[1]->id);
    }

    public function test_capacity_ignores_cancelled_and_completed_bookings(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'cancelled');
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'completed');

        $result = $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:15');

        $this->assertSame($location->id, $result[0]->id);
    }

    public function test_availability_slot_finder_returns_free_slots(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['duration' => 30]);
        $location->update(['hours' => ['tue' => '10:00 - 12:00']]);
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'confirmed');

        $slots = app(AvailabilitySlotFinder::class)->find($salon, $location->id, $service->id, '2026-04-28');

        $this->assertSame(['10:30', '11:00', '11:30'], $slots);
    }

    public function test_availability_slot_finder_respects_staff_availability(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['duration' => 30]);
        $location->update(['hours' => ['tue' => '10:00 - 12:00'], 'max_concurrent_bookings' => 2]);
        $service->update(['max_concurrent_bookings' => 2]);
        $staff = $this->createAssignableStaff($salon, $location, $service, [
            'working_hours' => ['tue' => '10:30 - 12:00'],
        ]);

        $slots = app(AvailabilitySlotFinder::class)->find($salon, $location->id, $service->id, '2026-04-28', $staff->id);

        $this->assertSame(['10:30', '11:00', '11:30'], $slots);
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

    public function test_booking_can_store_staff_id_and_relationship_works(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $staff = $this->createAssignableStaff($salon, $location, $service);

        $booking = $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'pending', $staff->id);

        $this->assertSame($staff->id, $booking->staff_id);
        $this->assertSame($staff->id, $booking->staffMember->id);
    }

    public function test_rejects_staff_id_from_another_salon(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        [$otherSalon, $otherLocation, $otherService] = $this->appointmentSetup();
        $otherStaff = $this->createAssignableStaff($otherSalon, $otherLocation, $otherService);

        $this->expectBookingError('Staff-ul selectat nu apartine salonului.');

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:00', $otherStaff->id);
    }

    public function test_rejects_inactive_staff(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $staff = $this->createAssignableStaff($salon, $location, $service, ['active' => false]);

        $this->expectBookingError("Staff-ul {$staff->name} nu este activ.");

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:00', $staff->id);
    }

    public function test_rejects_staff_not_assigned_to_selected_service(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $staff = $salon->staff()->create(['name' => 'Ana', 'active' => true]);
        $staff->locations()->attach($location);

        $this->expectBookingError("Staff-ul {$staff->name} nu este alocat serviciului {$service->name}.");

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:00', $staff->id);
    }

    public function test_rejects_staff_not_assigned_to_selected_location(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $otherLocation = $salon->locations()->create(['name' => 'Nord', 'address' => 'Second Street']);
        $staff = $this->createAssignableStaff($salon, $otherLocation, $service);

        $this->expectBookingError("Staff-ul {$staff->name} nu lucreaza la locatia selectata.");

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:00', $staff->id);
    }

    public function test_allows_staff_with_matching_service_and_location(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $staff = $this->createAssignableStaff($salon, $location, $service);

        $result = $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:00', $staff->id);

        $this->assertSame($staff->id, $result[3]->id);
    }

    public function test_respects_legacy_staff_location_id_fallback_when_no_location_pivot_exists(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $staff = $salon->staff()->create([
            'name' => 'Ana',
            'active' => true,
            'location_id' => $location->id,
        ]);
        $service->staffMembers()->attach($staff);

        $result = $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:00', $staff->id);

        $this->assertSame($staff->id, $result[3]->id);
    }

    public function test_rejects_booking_outside_staff_working_hours(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $staff = $this->createAssignableStaff($salon, $location, $service, [
            'working_hours' => ['tue' => '10:00 - 15:00'],
        ]);

        $this->expectBookingError("Ora 09:30 este in afara programului staff-ului {$staff->name} (10:00 - 15:00).");

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '09:30', $staff->id);
    }

    public function test_rejects_booking_ending_after_staff_working_hours(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['duration' => 60]);
        $staff = $this->createAssignableStaff($salon, $location, $service, [
            'working_hours' => ['tue' => '10:00 - 15:00'],
        ]);

        $this->expectBookingError("Programarea se termina la 15:30, dupa programul staff-ului {$staff->name} (15:00).");

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '14:30', $staff->id);
    }

    public function test_rejects_overlapping_booking_for_same_staff(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['duration' => 60]);
        $location->update(['max_concurrent_bookings' => 2]);
        $service->update(['max_concurrent_bookings' => 2]);
        $staff = $this->createAssignableStaff($salon, $location, $service);
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'confirmed', $staff->id);

        $this->expectBookingError("Staff-ul {$staff->name} are deja o programare in intervalul selectat.");

        $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:30', $staff->id);
    }

    public function test_allows_same_time_booking_for_different_staff(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup(['duration' => 60]);
        $location->update(['max_concurrent_bookings' => 2]);
        $service->update(['max_concurrent_bookings' => 2]);
        $staffA = $this->createAssignableStaff($salon, $location, $service, ['name' => 'Ana']);
        $staffB = $this->createAssignableStaff($salon, $location, $service, ['name' => 'Maria']);
        $this->createBooking($salon, $location, $service, '2026-04-28', '10:00', 'confirmed', $staffA->id);

        $result = $this->checker()->check($salon, $location->id, $service->id, '2026-04-28', '10:00', $staffB->id);

        $this->assertSame($staffB->id, $result[3]->id);
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

    public function test_booking_creator_stores_staff_id_and_staff_name_when_provided(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();
        $staff = $this->createAssignableStaff($salon, $location, $service);

        $booking = app(BookingCreator::class)->createFromAiFunctionCall($salon, [
            'client_name' => 'Ana Pop',
            'client_phone' => '0700000000',
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'staff_id' => (string) $staff->id,
            'date' => '2026-04-28',
            'time' => '10:00',
        ]);

        $this->assertSame($staff->id, $booking->staff_id);
        $this->assertSame([$staff->name], $booking->staff);
    }

    public function test_booking_creator_stores_source_when_provided(): void
    {
        [$salon, $location, $service] = $this->appointmentSetup();

        $booking = app(BookingCreator::class)->createFromAiFunctionCall($salon, [
            'client_name' => 'Ana Pop',
            'client_phone' => '0700000000',
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'date' => '2026-04-28',
            'time' => '10:00',
        ], 'ai_assistant');

        $this->assertSame('ai_assistant', $booking->source);
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

        return [$salon, $location, $service, $user];
    }

    private function createBooking(Salon $salon, Location $location, Service $service, string $date, string $time, string $status, ?int $staffId = null): Booking
    {
        return $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'staff_id' => $staffId,
            'client_name' => 'Client existent',
            'client_phone' => '0700000001',
            'date' => $date,
            'time' => $time,
            'status' => $status,
        ]);
    }

    private function createAssignableStaff(Salon $salon, Location $location, Service $service, array $overrides = [])
    {
        $staff = $salon->staff()->create(array_merge([
            'name' => 'Ana',
            'active' => true,
        ], $overrides));
        $staff->locations()->attach($location);
        $service->staffMembers()->attach($staff);

        return $staff;
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
