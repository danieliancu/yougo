<?php

namespace App\Http\Controllers;

use App\Services\PublicAssistant\YouGoPublicAssistantService;
use App\Support\YouGoHelpRoutes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicYouGoAssistantController extends Controller
{
    public function __construct(private readonly YouGoPublicAssistantService $assistant)
    {
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['nullable', Rule::in(['ro', 'en'])],
            'messages' => ['required', 'array', 'min:1', 'max:15'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:2000'],
            'context' => ['nullable', 'array'],
            'context.surface' => ['nullable', Rule::in(['public', 'dashboard'])],
            'context.current_path' => ['nullable', 'string', 'max:255'],
            'context.current_section' => ['nullable', 'string', 'max:80'],
            'context.authenticated' => ['nullable', 'boolean'],
            'context.plan_key' => ['nullable', 'string', 'max:80'],
            'context.business_name' => ['nullable', 'string', 'max:120'],
        ]);

        $messages = collect($data['messages'])
            ->map(fn (array $message) => [
                'role' => $message['role'],
                'content' => trim($message['content']),
            ])
            ->filter(fn (array $message) => $message['content'] !== '')
            ->values()
            ->all();

        abort_if(empty($messages) || end($messages)['role'] !== 'user', 422, 'The last message must be from the visitor.');

        $context = $data['context'] ?? [];
        $latestUserMessage = end($messages)['content'];
        $message = $this->assistant->reply($messages, $data['locale'] ?? 'ro', $context);
        $actions = YouGoHelpRoutes::actionsFor($latestUserMessage, $context, $data['locale'] ?? 'ro')
            ?: YouGoHelpRoutes::actionsFor($message, $context, $data['locale'] ?? 'ro');

        return response()->json(array_filter([
            'message' => $message,
            'actions' => $actions,
        ], fn ($value) => $value !== []));
    }
}
