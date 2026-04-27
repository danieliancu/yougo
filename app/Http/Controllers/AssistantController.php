<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Services\Assistant\AssistantChatService;
use App\Services\Conversation\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantChatService $assistantChatService,
        private readonly ConversationService $conversationService,
    ) {
    }

    public function show(Salon $salon): Response
    {
        $salon->load(['locations', 'services']);

        return Inertia::render('Assistant/Show', [
            'salon' => $salon,
            'locale' => $salon->display_language ?? config('app.locale', 'ro'),
        ]);
    }

    public function abandon(Request $request, Salon $salon): JsonResponse
    {
        $this->conversationService->abandon($salon, (int) $request->input('conversation_id'));

        return response()->json(['ok' => true]);
    }

    public function chat(Request $request, Salon $salon): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:3000'],
        ]);

        $result = $this->assistantChatService->handle($salon, $data);

        return response()->json($result['body'], $result['status']);
    }
}
