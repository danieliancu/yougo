<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingStatusChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $oldStatus,
        public readonly string $newStatus,
    ) {
    }

    public function envelope(): Envelope
    {
        $clientName = $this->booking->client_name ?? '';
        $subject = match ($this->newStatus) {
            'confirmed' => "Programare confirmata pentru {$clientName}",
            'cancelled' => "Programare anulata pentru {$clientName}",
            'completed' => "Programare finalizata pentru {$clientName}",
            default => "Status programare actualizat pentru {$clientName}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.booking-status-changed');
    }
}
