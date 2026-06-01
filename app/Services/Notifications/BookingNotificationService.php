<?php

namespace App\Services\Notifications;

use App\Mail\NewAiBookingMail;
use App\Mail\BookingChangeRequestMail;
use App\Mail\BookingCancelledByCustomerMail;
use App\Models\Booking;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BookingNotificationService
{
    public function sendBookingChangeRequestNotification(Conversation $conversation, array $changeRequest): bool
    {
        $conversation->loadMissing(['salon', 'booking.location', 'booking.service', 'booking.staffMember']);
        $salon = $conversation->salon;

        if (! $salon || ! filled($salon->notification_email)) {
            return false;
        }

        if (! ($salon->booking_confirmations ?? true)) {
            return false;
        }

        try {
            Mail::to($salon->notification_email)->send(
                new BookingChangeRequestMail($conversation, $changeRequest)
            );

            return true;
        } catch (Throwable $exception) {
            Log::warning('Booking change request notification could not be sent.', [
                'conversation_id' => $conversation->id,
                'booking_id' => $conversation->booking_id,
                'salon_id' => $salon->id,
                'recipient_email' => $salon->notification_email,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function sendAiBookingNotification(Booking $booking, ?Conversation $conversation = null): void
    {
        $booking->loadMissing(['salon', 'service', 'location', 'staffMember']);
        $salon = $booking->salon;

        if (! $salon || $booking->notification_sent_at) {
            return;
        }

        if (! in_array($booking->source, ['ai_assistant', 'whatsapp'], true)) {
            return;
        }

        if (! filled($salon->notification_email)) {
            return;
        }

        if (! ($salon->booking_confirmations ?? true)) {
            return;
        }

        try {
            Mail::to($salon->notification_email)->send(
                new NewAiBookingMail($booking, $conversation?->summary)
            );

            $booking->forceFill([
                'notification_sent_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('AI booking notification could not be sent.', [
                'booking_id' => $booking->id,
                'salon_id' => $salon->id,
                'recipient_email' => $salon->notification_email,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function sendCustomerCancelledBookingNotification(Booking $booking, string $cancellationText, string $source = 'WhatsApp'): void
    {
        $booking->loadMissing(['salon', 'service', 'location', 'staffMember']);
        $salon = $booking->salon;

        if (! $salon || ! filled($salon->notification_email)) {
            return;
        }

        if (! ($salon->booking_confirmations ?? true)) {
            return;
        }

        try {
            Mail::to($salon->notification_email)->send(
                new BookingCancelledByCustomerMail($booking, $cancellationText, $source)
            );
        } catch (Throwable $exception) {
            Log::warning('Customer booking cancellation notification could not be sent.', [
                'booking_id' => $booking->id,
                'salon_id' => $salon->id,
                'recipient_email' => $salon->notification_email,
                'source' => $source,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
