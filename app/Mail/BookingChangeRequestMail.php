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

        return new Envelope(
            subject: "Cerere de modificare programare pentru {$businessName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-change-request',
        );
    }
}
