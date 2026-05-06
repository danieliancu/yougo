<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TranscribeController extends Controller
{
    public function __invoke(Request $request, Salon $salon)
    {
        $request->validate([
            'audio' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('audio');
        $audioData = base64_encode(file_get_contents($file->getRealPath()));

        // Use browser-reported MIME type (getClientMimeType), strip codec params
        // PHP's getMimeType() detects WebM containers as video/webm regardless of content
        $rawMime = $file->getClientMimeType() ?: 'audio/webm';
        $mimeType = strtok($rawMime, ';') ?: 'audio/webm';

        \Log::info('Transcribe mime', ['raw' => $rawMime, 'clean' => $mimeType]);

        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $response = Http::withOptions([
                'proxy' => '',
                'verify' => config('services.gemini.ca_bundle'),
            ])
            ->timeout(30)
            ->post($endpoint . '?key=' . config('services.gemini.key'), [
                'contents' => [[
                    'parts' => [
                        ['text' => 'Transcribe the speech in this audio. Return only the transcribed text, nothing else. Preserve the original language.'],
                        [
                            'inlineData' => [
                                'mimeType' => $mimeType,
                                'data' => $audioData,
                            ],
                        ],
                    ],
                ]],
            ]);

        if (! $response->successful()) {
            \Log::error('Transcribe failed', ['status' => $response->status(), 'body' => $response->body()]);
            return response()->json(['error' => 'transcription_failed', 'detail' => $response->json('error.message')], 500);
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! $text) {
            return response()->json(['error' => 'transcription_empty'], 422);
        }

        return response()->json(['text' => trim($text)]);
    }
}
