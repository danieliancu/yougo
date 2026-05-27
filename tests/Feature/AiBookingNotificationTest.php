<?php

namespace Tests\Feature;

use App\Mail\NewAiBookingMail;
use App\Mail\TestEmailMail;
use App\Models\Salon;
use App\Models\User;
use App\Services\Booking\BookingCreator;
use App\Services\Notifications\BookingNotificationService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class AiBookingNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_email_when_ai_creates_booking_and_settings_allow_it(): void
    {
        Mail::fake();
        [$salon, $booking] = $this->createAiBooking([
            'notification_email' => 'programari@client-business.test',
            'email_notifications' => true,
            'booking_confirmations' => true,
        ]);

        app(BookingNotificationService::class)->sendAiBookingNotification($booking);

        Mail::assertSent(NewAiBookingMail::class, fn ($mail) => $mail->hasTo('programari@client-business.test'));
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

    public function test_sends_booking_notification_on_free_plan_when_settings_allow_it(): void
    {
        Mail::fake();
        [, $booking] = $this->createAiBooking([
            'plan' => 'free',
            'notification_email' => 'owner@example.com',
            'email_notifications' => true,
            'booking_confirmations' => true,
        ]);

        app(BookingNotificationService::class)->sendAiBookingNotification($booking);

        Mail::assertSent(NewAiBookingMail::class, fn ($mail) => $mail->hasTo('owner@example.com'));
        $this->assertNotNull($booking->refresh()->notification_sent_at);
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

    public function test_booking_notification_does_not_use_mail_from_address_as_recipient(): void
    {
        Mail::fake();
        Config::set('mail.from.address', 'notifications@yougo.test');
        [, $booking] = $this->createAiBooking([
            'notification_email' => 'programari@client-business.test',
        ]);

        app(BookingNotificationService::class)->sendAiBookingNotification($booking);

        Mail::assertSent(NewAiBookingMail::class, fn ($mail) => $mail->hasTo('programari@client-business.test'));
        Mail::assertNotSent(NewAiBookingMail::class, fn ($mail) => $mail->hasTo('notifications@yougo.test'));
    }

    public function test_booking_creation_does_not_fail_if_notification_send_throws_exception(): void
    {
        Log::spy();
        Mail::shouldReceive('to')
            ->once()
            ->with('programari@client-business.test')
            ->andThrow(new RuntimeException('SMTP transport failed'));

        [, $booking] = $this->createAiBooking([
            'notification_email' => 'programari@client-business.test',
        ]);

        app(BookingNotificationService::class)->sendAiBookingNotification($booking);

        $this->assertSame('pending', $booking->refresh()->status);
        $this->assertNull($booking->notification_sent_at);
        Log::shouldHaveReceived('warning')->once()->with('AI booking notification could not be sent.', [
            'booking_id' => $booking->id,
            'salon_id' => $booking->salon_id,
            'recipient_email' => 'programari@client-business.test',
            'error' => 'SMTP transport failed',
        ]);
    }

    public function test_test_email_command_exists(): void
    {
        $this->assertArrayHasKey('yougo:test-email', Artisan::all());
    }

    public function test_test_email_command_sends_mail(): void
    {
        Mail::fake();

        $exitCode = Artisan::call('yougo:test-email', [
            'email' => 'recipient@example.com',
        ]);

        $this->assertSame(0, $exitCode);
        Mail::assertSent(TestEmailMail::class, fn ($mail) => $mail->hasTo('recipient@example.com'));
        $this->assertStringContainsString('Test email sent to recipient@example.com.', Artisan::output());
    }

    public function test_env_example_contains_resend_key_placeholder(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('RESEND_KEY=', $envExample);
        $this->assertStringContainsString('MAIL_MAILER=log', $envExample);
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
            'plan' => 'website_chat',
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
            'date' => now()->next(CarbonInterface::TUESDAY)->toDateString(),
            'time' => '10:00',
        ], $source);

        if (array_key_exists('notification_sent_at', $salonOverrides)) {
            $booking->forceFill(['notification_sent_at' => $salonOverrides['notification_sent_at']])->save();
        }

        return [$salon, $booking->refresh()];
    }
}
