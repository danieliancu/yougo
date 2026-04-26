<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
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
}
