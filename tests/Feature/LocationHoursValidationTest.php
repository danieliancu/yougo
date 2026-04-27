<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationHoursValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_hours_are_normalized_when_location_is_created(): void
    {
        $user = User::factory()->create();
        $salon = Salon::query()->create([
            'user_id' => $user->id,
            'name' => 'YouGo Studio',
        ]);

        $this->actingAs($user)->post('/locations', [
            'name' => 'Nordului',
            'address' => 'Sos. Nordului',
            'hours' => [
                'mon' => '8:00-20:00',
                'tue' => '08:00 – 20:00',
                'wed' => 'closed',
            ],
        ])->assertSessionHasNoErrors();

        $location = $salon->locations()->first();

        $this->assertSame('08:00 - 20:00', $location->hours['mon']);
        $this->assertSame('08:00 - 20:00', $location->hours['tue']);
        $this->assertSame('Inchis', $location->hours['wed']);
    }

    public function test_location_hours_reject_impossible_hours(): void
    {
        $user = User::factory()->create();
        Salon::query()->create([
            'user_id' => $user->id,
            'name' => 'YouGo Studio',
        ]);

        $this->actingAs($user)->post('/locations', [
            'name' => 'Nordului',
            'address' => 'Sos. Nordului',
            'hours' => [
                'mon' => '80:00-20:00',
            ],
        ])->assertSessionHasErrors([
            'hours.mon' => 'Program invalid. Orele trebuie sa fie intre 00:00 si 23:59.',
        ]);
    }
}
