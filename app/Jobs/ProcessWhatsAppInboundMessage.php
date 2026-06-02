<?php

namespace App\Jobs;

use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Models\WhatsappIntegration;
use App\Services\WhatsApp\WhatsAppAiReplyService;
use App\Support\YouGoServices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWhatsAppInboundMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const MODE_TEXT = 'text';

    public const MODE_UNSUPPORTED_MEDIA = 'unsupported_media';

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $inboundMessageId,
        public readonly int $salonId,
        public readonly int $integrationId,
        public readonly string $messageSid,
        public readonly array $payload,
        public readonly string $mode = self::MODE_TEXT,
    ) {
        $this->onQueue('whatsapp');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return $this->messageSid !== ''
            ? 'whatsapp-inbound:'.$this->messageSid
            : 'whatsapp-inbound-message:'.$this->inboundMessageId;
    }

    public function handle(WhatsAppAiReplyService $aiReplies): void
    {
        $claim = $this->claimInboundMessage();

        if (! $claim) {
            return;
        }

        [$salon, $integration, $inboundMessage] = $claim;
        $conversation = $inboundMessage->conversation;

        Log::info('WhatsApp AI job started', [
            'salon_id' => $salon->id,
            'integration_id' => $integration->id,
            'conversation_id' => $conversation?->id,
            'inbound_message_id' => $inboundMessage->id,
            'message_sid' => $this->messageSid ?: null,
            'mode' => $this->mode,
        ]);

        try {
            if ($this->mode === self::MODE_UNSUPPORTED_MEDIA) {
                if ($conversation) {
                    $aiReplies->sendUnsupportedTextMessage($salon, $integration, $conversation, $inboundMessage);
                }
            } else {
                $aiReplies->handleInbound($salon, $integration, $inboundMessage, $this->payload);
            }

            $this->markInboundProcessed($inboundMessage->id);

            Log::info('WhatsApp AI job completed', [
                'salon_id' => $salon->id,
                'integration_id' => $integration->id,
                'conversation_id' => $conversation?->id,
                'inbound_message_id' => $inboundMessage->id,
                'message_sid' => $this->messageSid ?: null,
                'mode' => $this->mode,
            ]);
        } catch (Throwable $exception) {
            $this->markInboundFailed($this->inboundMessageId, $exception);

            Log::error('WhatsApp AI job failed', [
                'salon_id' => $salon->id,
                'integration_id' => $integration->id,
                'conversation_id' => $conversation?->id,
                'inbound_message_id' => $inboundMessage->id,
                'message_sid' => $this->messageSid ?: null,
                'mode' => $this->mode,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function claimInboundMessage(): ?array
    {
        return DB::transaction(function () {
            $inboundMessage = ConversationMessage::query()
                ->with(['conversation'])
                ->whereKey($this->inboundMessageId)
                ->lockForUpdate()
                ->first();

            if (! $inboundMessage) {
                Log::warning('WhatsApp AI job skipped because inbound message was missing', [
                    'inbound_message_id' => $this->inboundMessageId,
                    'message_sid' => $this->messageSid ?: null,
                ]);

                return null;
            }

            $metadata = $inboundMessage->metadata ?? [];

            if (($metadata['ai_reply_processed_at'] ?? null) || $this->outboundExistsFor($inboundMessage)) {
                $this->updateInboundMetadata($inboundMessage, [
                    'ai_reply_processed_at' => $metadata['ai_reply_processed_at'] ?? now()->toISOString(),
                ]);

                Log::info('WhatsApp AI job skipped duplicate inbound message', [
                    'conversation_id' => $inboundMessage->conversation_id,
                    'inbound_message_id' => $inboundMessage->id,
                    'message_sid' => $this->messageSid ?: null,
                ]);

                return null;
            }

            if (
                ($metadata['ai_reply_processing_started_at'] ?? null)
                && ! ($metadata['ai_reply_failed_at'] ?? null)
                && $this->processingClaimIsFresh((string) $metadata['ai_reply_processing_started_at'])
            ) {
                Log::info('WhatsApp AI job skipped inbound already being processed', [
                    'conversation_id' => $inboundMessage->conversation_id,
                    'inbound_message_id' => $inboundMessage->id,
                    'message_sid' => $this->messageSid ?: null,
                ]);

                return null;
            }

            $salon = Salon::query()->find($this->salonId);
            $integration = WhatsappIntegration::query()->find($this->integrationId);

            if (! $salon || ! $integration || ! $inboundMessage->conversation) {
                Log::warning('WhatsApp AI job skipped missing records', [
                    'salon_id' => $this->salonId,
                    'integration_id' => $this->integrationId,
                    'conversation_id' => $inboundMessage->conversation_id,
                    'inbound_message_id' => $inboundMessage->id,
                    'message_sid' => $this->messageSid ?: null,
                ]);

                return null;
            }

            if (
                $integration->salon_id !== $salon->id
                || $inboundMessage->conversation->salon_id !== $salon->id
                || $integration->status !== WhatsappIntegration::STATUS_ACTIVE
                || ! $integration->ai_enabled
                || ! YouGoServices::planHasWhatsappAi($salon->plan)
            ) {
                Log::info('WhatsApp AI job skipped ineligible inbound message', [
                    'salon_id' => $salon->id,
                    'integration_id' => $integration->id,
                    'conversation_id' => $inboundMessage->conversation_id,
                    'inbound_message_id' => $inboundMessage->id,
                    'message_sid' => $this->messageSid ?: null,
                    'integration_status' => $integration->status,
                    'ai_enabled' => $integration->ai_enabled,
                    'plan' => $salon->plan,
                ]);

                return null;
            }

            $this->updateInboundMetadata($inboundMessage, [
                'ai_reply_processing_started_at' => now()->toISOString(),
                'ai_reply_failed_at' => null,
                'ai_reply_last_error' => null,
                'ai_reply_attempts' => (int) ($metadata['ai_reply_attempts'] ?? 0) + 1,
                'ai_reply_mode' => $this->mode,
            ]);

            return [$salon, $integration, $inboundMessage->refresh()->load('conversation')];
        });
    }

    private function markInboundProcessed(int $inboundMessageId): void
    {
        DB::transaction(function () use ($inboundMessageId) {
            $inboundMessage = ConversationMessage::query()
                ->whereKey($inboundMessageId)
                ->lockForUpdate()
                ->first();

            if (! $inboundMessage) {
                return;
            }

            $this->updateInboundMetadata($inboundMessage, [
                'ai_reply_processed_at' => now()->toISOString(),
                'ai_reply_failed_at' => null,
                'ai_reply_last_error' => null,
            ]);
        });
    }

    private function markInboundFailed(int $inboundMessageId, Throwable $exception): void
    {
        DB::transaction(function () use ($inboundMessageId, $exception) {
            $inboundMessage = ConversationMessage::query()
                ->whereKey($inboundMessageId)
                ->lockForUpdate()
                ->first();

            if (! $inboundMessage) {
                return;
            }

            $this->updateInboundMetadata($inboundMessage, [
                'ai_reply_failed_at' => now()->toISOString(),
                'ai_reply_last_error' => $exception::class,
            ]);
        });
    }

    private function outboundExistsFor(ConversationMessage $inboundMessage): bool
    {
        return ConversationMessage::query()
            ->where('conversation_id', $inboundMessage->conversation_id)
            ->where('direction', 'outbound')
            ->where('metadata->inbound_message_id', $inboundMessage->id)
            ->exists();
    }

    private function processingClaimIsFresh(string $startedAt): bool
    {
        try {
            return Carbon::parse($startedAt)->greaterThan(now()->subSeconds($this->timeout + 30));
        } catch (Throwable) {
            return false;
        }
    }

    private function updateInboundMetadata(ConversationMessage $message, array $metadata): void
    {
        $message->forceFill([
            'metadata' => [
                ...($message->metadata ?? []),
                ...$metadata,
            ],
        ])->save();
    }
}
