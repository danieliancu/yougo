<?php

namespace App\Console\Commands;

use App\Models\Salon;
use App\Models\WhatsappIntegration;
use App\Services\WhatsApp\TwilioWhatsAppService;
use Illuminate\Console\Command;

class WhatsappActivateCommand extends Command
{
    protected $signature = 'yougo:whatsapp-activate {salon_id} {twilio_sender} {--display-number=}';

    protected $description = 'Manually activate a Twilio WhatsApp sender for a salon.';

    public function handle(TwilioWhatsAppService $twilio): int
    {
        $salon = Salon::query()->find($this->argument('salon_id'));
        if (! $salon) {
            $this->error('Salon not found.');

            return self::FAILURE;
        }

        $sender = $twilio->normalizeWhatsappSender((string) $this->argument('twilio_sender'));
        if (! preg_match('/^whatsapp:\+\d{8,15}$/', $sender)) {
            $this->error('Enter the WhatsApp sender in international format, for example whatsapp:+407...');

            return self::FAILURE;
        }

        $existing = WhatsappIntegration::query()
            ->where('twilio_sender', $sender)
            ->where('salon_id', '!=', $salon->id)
            ->first();

        if ($existing) {
            $this->error("WhatsApp sender {$sender} is already assigned to salon {$existing->salon_id}.");

            return self::FAILURE;
        }

        $current = $salon->whatsappIntegration;
        $metadata = $current?->metadata ?? [];
        $displayNumber = trim((string) ($this->option('display-number') ?: str_replace('whatsapp:', '', $sender)));

        $integration = $salon->whatsappIntegration()->updateOrCreate(
            ['salon_id' => $salon->id],
            [
                'provider' => 'twilio',
                'twilio_sender' => $sender,
                'display_number' => $displayNumber,
                'status' => WhatsappIntegration::STATUS_ACTIVE,
                'activated_at' => now(),
                'metadata' => [
                    ...$metadata,
                    'activation_source' => 'manual',
                    'activated_by' => 'command',
                ],
            ],
        );

        $this->info("WhatsApp integration {$integration->id} activated for salon {$salon->id} with sender {$sender}.");

        return self::SUCCESS;
    }
}
