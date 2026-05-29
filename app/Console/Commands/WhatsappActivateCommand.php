<?php

namespace App\Console\Commands;

use App\Models\Salon;
use App\Models\WhatsappIntegration;
use App\Services\WhatsApp\TwilioWhatsAppService;
use Illuminate\Console\Command;

class WhatsappActivateCommand extends Command
{
    protected $signature = 'yougo:whatsapp-activate {salon_id} {twilio_sender}';

    protected $description = 'Manually activate a Twilio WhatsApp sender for a salon.';

    public function handle(TwilioWhatsAppService $twilio): int
    {
        $salon = Salon::query()->find($this->argument('salon_id'));
        if (! $salon) {
            $this->error('Salon not found.');

            return self::FAILURE;
        }

        $sender = $twilio->normalizeAddress((string) $this->argument('twilio_sender'));

        $integration = $salon->whatsappIntegration()->updateOrCreate(
            ['salon_id' => $salon->id],
            [
                'provider' => 'twilio',
                'twilio_sender' => $sender,
                'display_number' => str_replace('whatsapp:', '', $sender),
                'status' => WhatsappIntegration::STATUS_ACTIVE,
                'activated_at' => now(),
            ],
        );

        $this->info("WhatsApp integration {$integration->id} activated for salon {$salon->id} with sender {$sender}.");

        return self::SUCCESS;
    }
}
