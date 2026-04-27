<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_page_loads_with_ai_settings_for_frontend(): void
    {
        $salon = $this->createSalon([
            'ai_assistant_name' => 'Mara',
            'ai_business_summary' => 'Welcome clients with a premium tone.',
            'ai_handoff_message' => 'Un coleg va reveni cu detalii.',
            'display_language' => 'ro',
        ]);

        $this->get("/assistant/{$salon->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Assistant/Show')
                ->where('locale', 'ro')
                ->where('salon.ai_assistant_name', 'Mara')
                ->where('salon.ai_business_summary', 'Welcome clients with a premium tone.')
                ->where('salon.ai_handoff_message', 'Un coleg va reveni cu detalii.')
            );
    }

    public function test_chat_creates_and_reuses_conversation_with_compatible_response_shape(): void
    {
        config(['services.gemini.key' => null]);
        $salon = $this->createSalon();

        $first = $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [
                ['role' => 'assistant', 'content' => 'Buna!'],
                ['role' => 'user', 'content' => 'Vreau o programare.'],
            ],
        ]);

        $first->assertStatus(503)
            ->assertJsonStructure(['message', 'conversation_id']);

        $conversationId = $first->json('conversation_id');
        $this->assertNotNull($conversationId);
        $this->assertSame(1, $salon->conversations()->count());

        $second = $this->postJson("/assistant/{$salon->id}/chat", [
            'conversation_id' => $conversationId,
            'messages' => [
                ['role' => 'assistant', 'content' => 'Buna!'],
                ['role' => 'user', 'content' => 'Vreau o programare.'],
                ['role' => 'assistant', 'content' => 'Pentru ce serviciu?'],
                ['role' => 'user', 'content' => 'Tuns.'],
            ],
        ]);

        $second->assertStatus(503)
            ->assertJsonStructure(['message', 'conversation_id'])
            ->assertJsonPath('conversation_id', $conversationId);

        $this->assertSame(1, $salon->conversations()->count());
        $this->assertSame(2, $salon->conversations()->first()->messages()->where('role', 'user')->count());
    }

    public function test_chat_reopens_abandoned_conversation_when_same_session_returns(): void
    {
        config(['services.gemini.key' => null]);
        $salon = $this->createSalon();
        $conversation = $salon->conversations()->create([
            'channel' => 'chat',
            'status' => 'completed',
            'intent' => 'abandoned',
            'summary' => 'Clientul a plecat temporar.',
            'last_message_at' => now()->subMinutes(5),
        ]);

        $response = $this->postJson("/assistant/{$salon->id}/chat", [
            'conversation_id' => $conversation->id,
            'messages' => [
                ['role' => 'assistant', 'content' => 'Buna!'],
                ['role' => 'user', 'content' => 'Am revenit.'],
            ],
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('conversation_id', $conversation->id);

        $conversation->refresh();
        $this->assertSame('open', $conversation->status);
        $this->assertSame('inquiry', $conversation->intent);
    }

    private function createSalon(array $attributes = []): Salon
    {
        $user = User::factory()->create();

        return $user->salon()->create(array_merge([
            'name' => 'YouGo Studio',
        ], $attributes));
    }
}
