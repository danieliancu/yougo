<?php

namespace Tests\Feature;

use App\Mail\BookingStatusChangedMail;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BookingStatusEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_status_change_email_when_booking_confirmed(): void
    {
        Mail::fake();
        [$user, $salon, $booking] = $this->createBookingWithSalon([
            'booking_status_email_notifications' => true,
            'notification_email' => 'owner@example.com',
        ]);

        $this->actingAs($user)
            ->put("/bookings/{$booking->id}", ['status' => 'confirmed']);

        Mail::assertSent(BookingStatusChangedMail::class, function ($mail) use ($booking) {
            return $mail->hasTo('owner@example.com')
                && $mail->booking->is($booking)
                && $mail->oldStatus === 'pending'
                && $mail->newStatus === 'confirmed';
        });
    }

    public function test_sends_status_change_email_when_booking_cancelled(): void
    {
        Mail::fake();
        [$user, , $booking] = $this->createBookingWithSalon([
            'booking_status_email_notifications' => true,
            'notification_email' => 'owner@example.com',
        ]);

        $this->actingAs($user)
            ->put("/bookings/{$booking->id}", ['status' => 'cancelled']);

        Mail::assertSent(BookingStatusChangedMail::class, fn ($mail) => $mail->newStatus === 'cancelled');
    }

    public function test_sends_status_change_email_when_booking_completed(): void
    {
        Mail::fake();
        [$user, , $booking] = $this->createBookingWithSalon([
            'booking_status_email_notifications' => true,
            'notification_email' => 'owner@example.com',
        ]);

        $this->actingAs($user)
            ->put("/bookings/{$booking->id}", ['status' => 'completed']);

        Mail::assertSent(BookingStatusChangedMail::class, fn ($mail) => $mail->newStatus === 'completed');
    }

    public function test_does_not_send_email_when_toggle_is_disabled(): void
    {
        Mail::fake();
        [$user, , $booking] = $this->createBookingWithSalon([
            'booking_status_email_notifications' => false,
            'notification_email' => 'owner@example.com',
        ]);

        $this->actingAs($user)
            ->put("/bookings/{$booking->id}", ['status' => 'confirmed']);

        Mail::assertNothingSent();
    }

    public function test_does_not_send_email_when_notification_email_is_missing(): void
    {
        Mail::fake();
        [$user, , $booking] = $this->createBookingWithSalon([
            'booking_status_email_notifications' => true,
            'notification_email' => null,
        ]);

        $this->actingAs($user)
            ->put("/bookings/{$booking->id}", ['status' => 'confirmed']);

        Mail::assertNothingSent();
    }

    public function test_does_not_send_email_when_status_does_not_change(): void
    {
        Mail::fake();
        [$user, , $booking] = $this->createBookingWithSalon([
            'booking_status_email_notifications' => true,
            'notification_email' => 'owner@example.com',
        ]);

        $booking->update(['status' => 'confirmed']);

        $this->actingAs($user)
            ->put("/bookings/{$booking->id}", ['status' => 'confirmed']);

        Mail::assertNothingSent();
    }

    public function test_does_not_send_email_on_non_status_update(): void
    {
        Mail::fake();
        [$user, , $booking] = $this->createBookingWithSalon([
            'booking_status_email_notifications' => true,
            'notification_email' => 'owner@example.com',
        ]);

        $this->actingAs($user)
            ->put("/bookings/{$booking->id}", ['client_name' => 'New Name']);

        Mail::assertNothingSent();
    }

    public function test_status_change_email_goes_to_business_notification_email_only(): void
    {
        Mail::fake();
        [$user, , $booking] = $this->createBookingWithSalon([
            'booking_status_email_notifications' => true,
            'notification_email' => 'business@example.com',
        ]);

        $this->actingAs($user)
            ->put("/bookings/{$booking->id}", ['status' => 'confirmed']);

        Mail::assertSent(BookingStatusChangedMail::class, fn ($mail) => $mail->hasTo('business@example.com'));
        Mail::assertSent(BookingStatusChangedMail::class, fn ($mail) => ! $mail->hasTo($user->email));
    }

    private function createBookingWithSalon(array $salonOverrides = []): array
    {
        $user = User::factory()->create();
        $salon = $user->salon()->create(array_merge([
            'name' => 'YouGo Studio',
            'plan' => 'website_chat',
            'notification_email' => 'owner@example.com',
            'booking_status_email_notifications' => true,
        ], $salonOverrides));
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

        $booking = $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Ana Pop',
            'client_phone' => '0700000000',
            'date' => now()->next(CarbonInterface::TUESDAY)->toDateString(),
            'time' => '10:00',
            'status' => 'pending',
            'source' => 'ai_assistant',
        ]);

        return [$user, $salon, $booking];
    }
}
