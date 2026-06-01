<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingCancelledByCustomerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $cancellationText,
        public readonly string $source = 'WhatsApp',
    ) {
    }

    public function envelope(): Envelope
    {
        $isEn = ($this->booking->salon?->display_language ?? config('app.locale', 'ro')) === 'en';

        return new Envelope(
            subject: $isEn ? 'Booking cancelled by customer' : 'Programare anulata de client',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.booking-cancelled-by-customer');
    }
}
