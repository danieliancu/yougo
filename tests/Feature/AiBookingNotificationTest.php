<?php

namespace Tests\Feature;

use App\Mail\NewAiBookingMail;
use App\Models\Salon;
use App\Models\User;
use App\Services\Booking\BookingCreator;
use App\Services\Notifications\BookingNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AiBookingNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_email_when_ai_creates_booking_and_settings_allow_it(): void
    {
        Mail::fake();
        [$salon, $booking] = $this->createAiBooking([
            'notification_email' => 'owner@example.com',
            'email_notifications' => true,
            'booking_confirmations' => true,
        ]);

        app(BookingNotificationService::class)->sendAiBookingNotification($booking);

        Mail::assertSent(NewAiBookingMail::class, fn ($mail) => $mail->hasTo('owner@example.com'));
        $this->assertNotNull($booking->refresh()->notification_sent_at);
        $this->assertSame('ai_assistant', $booking->source);
        $this->assertSame($salon->id, $booking->salon_id);
    }

    public function test_does_not_send_if_notification_email_is_missing(): void
    {
        Mail::fake();
        [, $booking] = $this->createAiBooking(['notification_email' => null]);

        app(BookingNotificationService::class)->sendAiBookingNotification($booking);

        Mail::assertNothingSent();
        $this->assertNull($booking->refresh()->notification_sent_at);
    }

    public function test_does_not_send_if_email_notifications_are_disabled(): void
    {
        Mail::fake();
        [, $booking] = $this->createAiBooking([
            'notification_email' => 'owner@example.com',
            'email_notifications' => false,
        ]);

        app(BookingNotificationService::class)->sendAiBookingNotification($booking);

        Mail::assertNothingSent();
        $this->assertNull($booking->refresh()->notification_sent_at);
    }

    public function test_does_not_send_if_booking_confirmations_are_disabled(): void
    {
        Mail::fake();
        [, $booking] = $this->createAiBooking([
            'notification_email' => 'owner@example.com',
            'booking_confirmations' => false,
        ]);

        app(BookingNotificationService::class)->sendAiBookingNotification($booking);

        Mail::assertNothingSent();
        $this->assertNull($booking->refresh()->notification_sent_at);
    }

    public function test_does_not_send_duplicate_notification_when_already_sent(): void
    {
        Mail::fake();
        [, $booking] = $this->createAiBooking([
            'notification_email' => 'owner@example.com',
            'notification_sent_at' => now(),
        ]);

        app(BookingNotificationService::class)->sendAiBookingNotification($booking);

        Mail::assertNothingSent();
    }

    public function test_does_not_send_for_non_ai_booking_source(): void
    {
        Mail::fake();
        [, $booking] = $this->createAiBooking([
            'notification_email' => 'owner@example.com',
        ], null);

        app(BookingNotificationService::class)->sendAiBookingNotification($booking);

        Mail::assertNothingSent();
    }

    public function test_booking_creation_still_succeeds_without_notification(): void
    {
        Mail::fake();
        [, $booking] = $this->createAiBooking(['notification_email' => null]);

        $this->assertSame('pending', $booking->status);
        $this->assertSame('ai_assistant', $booking->source);
    }

    private function createAiBooking(array $salonOverrides = [], ?string $source = 'ai_assistant'): array
    {
        $user = User::factory()->create();
        $salon = $user->salon()->create(array_merge([
            'name' => 'YouGo Studio',
            'notification_email' => 'owner@example.com',
            'email_notifications' => true,
            'booking_confirmations' => true,
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

        $booking = app(BookingCreator::class)->createFromAiFunctionCall($salon, [
            'client_name' => 'Ana Pop',
            'client_phone' => '0700000000',
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'date' => '2026-04-28',
            'time' => '10:00',
        ], $source);

        if (array_key_exists('notification_sent_at', $salonOverrides)) {
            $booking->forceFill(['notification_sent_at' => $salonOverrides['notification_sent_at']])->save();
        }

        return [$salon, $booking->refresh()];
    }
}
