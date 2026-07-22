<?php

namespace App\Services\Business;

use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Models\Salon;
use Illuminate\Database\Eloquent\Builder;

/**
 * List/filter/counts for the Request dashboard module — mirrors
 * App\Services\CRM\CustomerDashboardService::index()'s shape and pagination style.
 */
class RequestDashboardService
{
    /**
     * @param  array{status?: ?string, priority?: ?string, type?: ?string, search?: ?string}  $filters
     */
    public function index(Salon $salon, array $filters = []): array
    {
        $status = $filters['status'] ?? null;
        $priority = $filters['priority'] ?? null;
        $type = $filters['type'] ?? null;
        $search = $filters['search'] ?? null;

        $requests = $salon->customerRequests()
            ->with(['location', 'service', 'assignee', 'sourceConversation'])
            ->when(filled($status), fn (Builder $query) => $query->where('status', $status))
            ->when(filled($priority), fn (Builder $query) => $query->where('priority', $priority))
            ->when(filled($type), fn (Builder $query) => $query->where('type', $type))
            ->when(filled($search), function (Builder $query) use ($search): void {
                $search = trim((string) $search);
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('client_name', 'like', "%{$search}%")
                        ->orWhere('client_phone', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return [
            'items' => $requests->through(fn ($request) => [
                'id' => $request->id,
                'type' => $request->type->value,
                'status' => $request->status->value,
                'priority' => $request->priority->value,
                'title' => $request->title,
                'description' => $request->description,
                'channel' => $request->channel,
                'client_name' => $request->client_name,
                'client_phone' => $request->client_phone,
                'preferred_date' => $request->preferred_date,
                'preferred_window' => $request->preferred_window,
                'location' => $request->location?->only(['id', 'name']),
                'service' => $request->service?->only(['id', 'name']),
                'assignee' => $request->assignee?->only(['id', 'name']),
                'conversation_id' => $request->sourceConversation?->id,
                'created_at' => $request->created_at?->toIso8601String(),
            ])->items(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'next_page_url' => $requests->nextPageUrl(),
                'prev_page_url' => $requests->previousPageUrl(),
            ],
            'filters' => [
                'status' => $status ?: '',
                'priority' => $priority ?: '',
                'type' => $type ?: '',
                'search' => $search ?: '',
            ],
            'counters' => [
                'new' => $salon->customerRequests()->where('status', RequestStatus::New->value)->count(),
                'urgent' => $salon->customerRequests()->where('priority', RequestPriority::Urgent->value)->whereNotIn('status', [RequestStatus::Resolved->value, RequestStatus::Closed->value])->count(),
                'in_progress' => $salon->customerRequests()->where('status', RequestStatus::InProgress->value)->count(),
                'resolved' => $salon->customerRequests()->where('status', RequestStatus::Resolved->value)->count(),
            ],
        ];
    }
}
