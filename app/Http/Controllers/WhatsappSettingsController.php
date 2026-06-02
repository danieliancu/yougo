<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Salon;
use App\Models\WhatsappIntegration;
use App\Services\WhatsApp\TwilioWhatsAppService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Support\YouGoServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WhatsappSettingsController extends Controller
{
    public function requestActivation(Request $request, TwilioWhatsAppService $twilio): JsonResponse
    {
        $salon = $this->salon($request);
        $this->ensureWhatsappPlan($salon);

        $data = $request->validate([
            'requested_number' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9\s().-]+$/'],
        ]);

        $requestedNumber = $twilio->normalizeInternationalPhoneNumber($data['requested_number']);

        if (! preg_match('/^\+\d{8,15}$/', $requestedNumber)) {
            throw ValidationException::withMessages([
                'requested_number' => __('Please enter the number in international format, for example +447...'),
            ]);
        }

        $integration = $salon->whatsappIntegration()->updateOrCreate(
            ['salon_id' => $salon->id],
            [
                'provider' => 'twilio',
                'requested_number' => $requestedNumber,
                'status' => WhatsappIntegration::STATUS_REQUESTED,
                'requested_at' => now(),
            ],
        );

        return response()->json([
            'integration' => $this->serializeIntegration($integration->refresh()),
            'message' => 'activation_requested',
        ]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $salon = $this->salon($request);
        $this->ensureWhatsappPlan($salon);

        $data = $request->validate([
            'ai_enabled' => ['required', 'boolean'],
        ]);

        $integration = $salon->whatsappIntegration;
        abort_unless($integration, 404);
        abort_unless($integration->status === WhatsappIntegration::STATUS_ACTIVE, 422, 'WhatsApp is not active yet.');

        $integration->update(['ai_enabled' => (bool) $data['ai_enabled']]);

        return response()->json([
            'integration' => $this->serializeIntegration($integration->refresh()),
        ]);
    }

    public function testMessage(Request $request, TwilioWhatsAppService $twilio, WhatsAppConversationService $conversations): JsonResponse
    {
        $salon = $this->salon($request);
        $this->ensureWhatsappPlan($salon);

        $data = $request->validate([
            'to' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9\s().-]+$/'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $integration = $salon->whatsappIntegration;
        abort_unless($integration, 404);
        abort_unless($integration->status === WhatsappIntegration::STATUS_ACTIVE, 422, 'WhatsApp is not active yet.');
        abort_unless(filled($integration->twilio_sender), 422, 'WhatsApp number is not configured.');

        $to = $twilio->normalizeAddress($data['to']);
        $result = $twilio->sendMessage($integration->twilio_sender, $to, $data['message']);

        $conversation = $this->resolveTestConversation($salon, $integration->twilio_sender, $to);
        $message = $conversations->saveOutbound($conversation, $data['message'], $result);

        return response()->json([
            'ok' => true,
            'message_id' => $message->id,
            'provider' => $result,
        ]);
    }

    private function salon(Request $request): Salon
    {
        $salon = $request->user()?->salon;

        abort_unless($salon, 403);

        return $salon;
    }

    private function ensureWhatsappPlan(Salon $salon): void
    {
        abort_unless(YouGoServices::planHasWhatsappAi($salon->plan), 403, 'WhatsApp AI requires a compatible plan.');
    }

    private function resolveTestConversation(Salon $salon, string $from, string $to): Conversation
    {
        return $salon->conversations()->firstOrCreate(
            [
                'channel' => 'whatsapp',
                'provider' => 'twilio',
                'external_contact_id' => $to,
                'external_sender' => $from,
            ],
            [
                'contact_phone' => $to,
                'status' => 'open',
                'intent' => 'inquiry',
                'summary' => 'Mesaj test WhatsApp trimis prin YouGo.',
                'last_message_at' => now(),
            ],
        );
    }

    private function serializeIntegration(WhatsappIntegration $integration): array
    {
        return [
            'id' => $integration->id,
            'provider' => $integration->provider,
            'requested_number' => $integration->requested_number,
            'twilio_sender' => $integration->twilio_sender,
            'display_number' => $integration->display_number,
            'status' => $integration->status,
            'ai_enabled' => $integration->ai_enabled,
            'last_verified_at' => $integration->last_verified_at?->toIso8601String(),
            'activated_at' => $integration->activated_at?->toIso8601String(),
            'requested_at' => $integration->requested_at?->toIso8601String(),
        ];
    }
}
