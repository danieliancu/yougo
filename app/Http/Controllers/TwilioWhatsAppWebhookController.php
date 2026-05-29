<?php

namespace App\Http\Controllers;

use App\Models\WhatsappIntegration;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Support\YouGoServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Twilio\Security\RequestValidator;

class TwilioWhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, WhatsAppConversationService $conversations)
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('Twilio WhatsApp webhook rejected invalid signature', [
                'url' => $request->fullUrl(),
                'to' => $request->input('To'),
                'message_sid' => $request->input('MessageSid'),
            ]);

            return response('Invalid signature', 403);
        }

        $payload = $request->all();
        $to = (string) ($payload['To'] ?? '');
        $messageSid = (string) ($payload['MessageSid'] ?? '');

        $integration = WhatsappIntegration::query()
            ->with('salon')
            ->where('provider', 'twilio')
            ->where('twilio_sender', $to)
            ->where('status', WhatsappIntegration::STATUS_ACTIVE)
            ->first();

        if (! $integration || ! $integration->salon) {
            Log::warning('Twilio WhatsApp webhook received for unknown sender', [
                'to' => $to,
                'message_sid' => $messageSid,
            ]);

            return $this->twiml();
        }

        if ($messageSid !== '' && $conversations->hasProviderMessage($messageSid)) {
            return $this->twiml();
        }

        $salon = $integration->salon;

        if (! YouGoServices::planHasWhatsappAi($salon->plan)) {
            Log::info('Twilio WhatsApp webhook ignored for salon without WhatsApp entitlement', [
                'salon_id' => $salon->id,
                'plan' => $salon->plan,
                'message_sid' => $messageSid,
            ]);

            return $this->twiml();
        }

        $conversations->saveInbound($salon, $payload);

        if (! $integration->ai_enabled) {
            return $this->twiml();
        }

        // AI replies are intentionally not connected in this foundation phase.
        Log::info('Twilio WhatsApp inbound saved; AI reply skipped for foundation phase.', [
            'salon_id' => $salon->id,
            'message_sid' => $messageSid,
        ]);

        return $this->twiml();
    }

    private function hasValidSignature(Request $request): bool
    {
        if (! (bool) config('twilio.validate_signature', true)) {
            return true;
        }

        $authToken = config('twilio.auth_token');
        if (! filled($authToken)) {
            return false;
        }

        $signature = (string) $request->header('X-Twilio-Signature', '');
        if ($signature === '') {
            return false;
        }

        return (new RequestValidator($authToken))->validate(
            $signature,
            $request->fullUrl(),
            $request->post(),
        );
    }

    private function twiml()
    {
        return response('<Response></Response>', 200)->header('Content-Type', 'text/xml');
    }
}
