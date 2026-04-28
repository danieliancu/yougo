<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_delete_account_and_owned_business_data(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/studio.svg', '<svg></svg>');

        $user = User::factory()->create();
        $salon = $user->salon()->create([
            'name' => 'Delete Me Studio',
            'logo_path' => 'logos/studio.svg',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);
        $location = $salon->locations()->create([
            'name' => 'Main',
            'address' => 'Main Street',
        ]);
        $service = $salon->services()->create([
            'name' => 'Consultation',
            'price' => '100',
            'duration' => 30,
        ]);
        $booking = $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Client',
            'date' => '2026-04-28',
            'time' => '10:00',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => 'chat',
            'status' => 'open',
            'intent' => 'inquiry',
        ]);
        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Hello',
        ]);
        $salon->staff()->create([
            'location_id' => $location->id,
            'name' => 'Ana',
        ]);

        $response = $this->actingAs($user)->delete('/account');

        $response->assertRedirect(route('home'));
        $this->assertGuest();
        Storage::disk('public')->assertMissing('logos/studio.svg');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('salons', ['id' => $salon->id]);
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
        $this->assertDatabaseMissing('services', ['id' => $service->id]);
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseCount('conversation_messages', 0);
        $this->assertDatabaseCount('staff', 0);
    }

    public function test_guests_cannot_delete_an_account(): void
    {
        $this->delete('/account')->assertRedirect('/login');
    }
}
