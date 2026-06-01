<?php

namespace App\Mail;

use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingChangeRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly array $changeRequest,
    ) {
    }

    public function envelope(): Envelope
    {
        $businessName = $this->conversation->salon?->name ?? 'YouGo';
        $locale = $this->salonLocale();

        return new Envelope(
            subject: $locale === 'en'
                ? "Booking change request for {$businessName}"
                : "Cerere de modificare programare pentru {$businessName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-change-request',
        );
    }

    public function salonLocale(): string
    {
        $salon = $this->conversation->salon;
        $languageMode = $salon?->ai_language_mode;

        if (in_array($languageMode, ['ro', 'en'], true)) {
            return $languageMode;
        }

        return $salon?->display_language === 'en' ? 'en' : 'ro';
    }
}
