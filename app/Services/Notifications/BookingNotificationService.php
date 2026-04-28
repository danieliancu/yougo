<?php

namespace App\Services\Notifications;

use App\Mail\NewAiBookingMail;
use App\Models\Booking;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BookingNotificationService
{
    public function sendAiBookingNotification(Booking $booking, ?Conversation $conversation = null): void
    {
        $booking->loadMissing(['salon', 'service', 'location', 'staffMember']);
        $salon = $booking->salon;

        if (! $salon || $booking->notification_sent_at) {
            return;
        }

        if ($booking->source !== 'ai_assistant') {
            return;
        }

        if (! filled($salon->notification_email)) {
            return;
        }

        if (! ($salon->email_notifications ?? true) || ! ($salon->booking_confirmations ?? true)) {
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
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
