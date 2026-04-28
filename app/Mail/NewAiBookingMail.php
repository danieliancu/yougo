<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewAiBookingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly ?string $conversationSummary = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $businessName = $this->booking->salon?->name ?? 'YouGo';

        return new Envelope(
            subject: "Cerere noua de programare AI pentru {$businessName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-ai-booking',
        );
    }
}
