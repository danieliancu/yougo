<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BusinessCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_salon_defaults_to_appointment_confirmed(): void
    {
        $salon = $this->createSalon();

        $this->assertSame('appointment', $salon->primaryCapability());
        $this->assertSame(['appointment'], $salon->enabledCapabilities());
        $this->assertTrue($salon->hasCapability('appointment'));
        $this->assertFalse($salon->hasCapability('request'));
        $this->assertTrue($salon->isCapabilitiesLocked());
    }

    public function test_new_salon_with_legacy_lead_mode_starts_unconfirmed_with_no_capability(): void
    {
        $salon = $this->createSalon(['mode' => Salon::MODE_LEAD]);

        $this->assertNull($salon->primaryCapability());
        $this->assertSame([], $salon->enabledCapabilities());
        $this->assertFalse($salon->isCapabilitiesLocked());
        $this->assertSame('default', $salon->capabilities_source);
    }

    public function test_new_salon_with_legacy_reservation_mode_starts_unconfirmed_with_no_capability(): void
    {
        $salon = $this->createSalon(['mode' => Salon::MODE_RESERVATION]);

        $this->assertNull($salon->primaryCapability());
        $this->assertSame([], $salon->enabledCapabilities());
        $this->assertFalse($salon->isCapabilitiesLocked());
    }

    public function test_set_capabilities_can_activate_request_as_primary(): void
    {
        $salon = $this->createSalon();

        $salon->setCapabilities('request', ['request', 'appointment'], Salon::CAPABILITIES_SOURCE_CONFIRMED);
        $salon->refresh();

        $this->assertSame('request', $salon->primaryCapability());
        $this->assertSame(['request', 'appointment'], $salon->enabledCapabilities());
        $this->assertTrue($salon->hasCapability('appointment'));
        $this->assertTrue($salon->hasCapability('request'));
    }

    public function test_set_capabilities_rejects_primary_not_in_enabled_list(): void
    {
        $salon = $this->createSalon();

        $this->expectException(InvalidArgumentException::class);

        $salon->setCapabilities('request', ['appointment'], Salon::CAPABILITIES_SOURCE_CUSTOM);
    }

    public function test_set_capabilities_rejects_reservation_in_enabled_list(): void
    {
        $salon = $this->createSalon();

        $this->expectException(InvalidArgumentException::class);

        $salon->setCapabilities('appointment', ['appointment', 'reservation'], Salon::CAPABILITIES_SOURCE_CUSTOM);
    }

    public function test_capabilities_source_distinguishes_default_confirmed_and_custom(): void
    {
        $salon = $this->createSalon(['mode' => Salon::MODE_LEAD]);
        $this->assertSame('default', $salon->capabilities_source);

        $salon->setCapabilities('request', ['request'], Salon::CAPABILITIES_SOURCE_CONFIRMED);
        $this->assertSame('confirmed', $salon->fresh()->capabilities_source);

        $salon->setCapabilities('appointment', ['appointment', 'request'], Salon::CAPABILITIES_SOURCE_CUSTOM);
        $this->assertSame('custom', $salon->fresh()->capabilities_source);
    }

    public function test_migration_backfill_maps_legacy_modes_correctly(): void
    {
        $user = User::factory()->create();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_07_22_000001_add_business_capabilities_to_salons_table.php');
        $migration->down();

        $appointmentId = \DB::table('salons')->insertGetId([
            'user_id' => $user->id, 'name' => 'Appointment Salon', 'mode' => 'appointment',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $leadId = \DB::table('salons')->insertGetId([
            'user_id' => $user->id, 'name' => 'Lead Salon', 'mode' => 'lead',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $reservationId = \DB::table('salons')->insertGetId([
            'user_id' => $user->id, 'name' => 'Reservation Salon', 'mode' => 'reservation',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $appointment = \DB::table('salons')->find($appointmentId);
        $this->assertSame('appointment', $appointment->primary_capability);
        $this->assertSame(['appointment'], json_decode($appointment->enabled_capabilities, true));
        $this->assertSame('confirmed', $appointment->capabilities_source);

        $lead = \DB::table('salons')->find($leadId);
        $this->assertNull($lead->primary_capability);
        $this->assertSame([], json_decode($lead->enabled_capabilities, true));
        $this->assertSame('default', $lead->capabilities_source);

        $reservation = \DB::table('salons')->find($reservationId);
        $this->assertNull($reservation->primary_capability);
        $this->assertSame([], json_decode($reservation->enabled_capabilities, true));
        $this->assertSame('default', $reservation->capabilities_source);
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
