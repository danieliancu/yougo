<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\Conversation\ConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        abort_unless($conversation->salon->user_id === $request->user()->id, 403);

        $conversation->delete();

        return back();
    }

    public function resolveBookingChangeRequest(Request $request, Conversation $conversation, string $requestId, ConversationService $conversations): RedirectResponse
    {
        abort_unless($conversation->salon->user_id === $request->user()->id, 403);

        $resolved = $conversations->resolveBookingChangeRequest($conversation, $requestId, $request->user()->id);
        abort_unless($resolved !== null, 404);

        return back()->with('success', __('The request was marked as resolved.'));
    }
}
