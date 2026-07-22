<?php

namespace App\Services\Modes\Request;

use App\Enums\RequestPriority;
use App\Enums\RequestType;
use App\Models\Conversation;
use App\Models\CustomerRequest;
use App\Models\Location;
use App\Models\Salon;
use App\Models\Service;
use App\Services\Conversation\ConversationResultService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Mirrors AppointmentToolHandler for the `request` capability: validates the AI's raw
 * tool-call output server-side (never trusted as-is) and creates the CustomerRequest
 * through ConversationResultService, so the same conversation-level lock/idempotency
 * guarantees apply as for Booking.
 */
class RequestToolHandler
{
    public function __construct(
        private readonly ConversationResultService $conversationResultService,
    ) {}

    public function canHandle(Salon $salon, array $functionCall): bool
    {
        return $salon->hasCapability(Salon::CAPABILITY_REQUEST) && ($functionCall['name'] ?? null) === 'createRequest';
    }

    public function handle(Salon $salon, Conversation $conversation, array $functionCall, string $channel = 'chat'): ?CustomerRequest
    {
        $args = $functionCall['args'] ?? [];

        $validated = Validator::make([
            'type' => Arr::get($args, 'type', RequestType::General->value),
            'priority' => Arr::get($args, 'priority', RequestPriority::Normal->value),
            'title' => Arr::get($args, 'title'),
            'description' => Arr::get($args, 'description'),
            'preferred_date' => Arr::get($args, 'preferred_date'),
            'preferred_window' => Arr::get($args, 'preferred_window'),
            'location_id' => Arr::get($args, 'location_id'),
            'service_id' => Arr::get($args, 'service_id'),
            'client_name' => Arr::get($args, 'client_name'),
            'client_phone' => Arr::get($args, 'client_phone'),
            'structured_data' => Arr::get($args, 'structured_data'),
        ], [
            'type' => ['required', 'string', 'in:'.implode(',', RequestType::values())],
            'priority' => ['required', 'string', 'in:'.implode(',', RequestPriority::values())],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'preferred_date' => ['nullable', 'string', 'max:255'],
            'preferred_window' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'client_name' => ['required', 'string', 'max:255'],
            'client_phone' => [($salon->ai_collect_phone ?? true) ? 'required' : 'nullable', 'string', 'max:255'],
            'structured_data' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        $locationId = $this->validatedOwnedId($salon, Location::class, $validated['location_id'] ?? null);
        $serviceId = $this->validatedOwnedId($salon, Service::class, $validated['service_id'] ?? null);
        $structuredData = $this->decodeStructuredData($validated['structured_data'] ?? null);

        return $this->conversationResultService->createAndAttach(
            $conversation,
            Conversation::RESULT_TYPE_CUSTOMER_REQUEST,
            fn () => $salon->customerRequests()->create([
                'channel' => $channel,
                'type' => $validated['type'],
                'priority' => $validated['priority'],
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'],
                'structured_data' => $structuredData,
                'preferred_date' => $validated['preferred_date'] ?? null,
                'preferred_window' => $validated['preferred_window'] ?? null,
                'location_id' => $locationId,
                'service_id' => $serviceId,
                'client_name' => $validated['client_name'],
                'client_phone' => $validated['client_phone'] ?? null,
                'idempotency_key' => "salon:{$salon->id}:conversation:{$conversation->id}:request",
            ]),
        );
    }

    private function validatedOwnedId(Salon $salon, string $modelClass, mixed $id): ?int
    {
        if ($id === null) {
            return null;
        }

        $exists = $modelClass::query()->where('salon_id', $salon->id)->whereKey((int) $id)->exists();

        if (! $exists) {
            throw new HttpException(422, 'ID invalid pentru acest business.');
        }

        return (int) $id;
    }

    private function decodeStructuredData(?string $raw): ?array
    {
        if (! filled($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
