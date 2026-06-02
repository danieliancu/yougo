<?php

namespace App\Mail;

use App\Models\Salon;
use App\Models\User;
use App\Models\WhatsappIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WhatsappSetupRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Salon $salon,
        public readonly ?User $user,
        public readonly array $form,
        public readonly ?WhatsappIntegration $integration,
    ) {}

    public function envelope(): Envelope
    {
        $businessName = $this->form['business_name'] ?: $this->salon->name;
        $subject = $this->salon->display_language === 'en'
            ? "WhatsApp AI setup request - {$businessName}"
            : "Cerere configurare WhatsApp AI - {$businessName}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.whatsapp-setup-request',
        );
    }
}
