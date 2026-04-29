<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Support\Facades\Http;
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

    public function test_chat_can_return_availability_slots_from_tool_call(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'checkAvailability',
                                'args' => [
                                    'location_id' => '1',
                                    'service_id' => '1',
                                    'date' => '2026-05-05',
                                ],
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $salon = $this->createSalon();
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['tue' => '10:00 - 11:00'],
        ]);
        $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);

        $response = $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [
                ['role' => 'user', 'content' => 'Ce ore sunt libere marti?'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Pentru marți, 5 mai, am gasit urmatoarele sloturi libere: 10:00, 10:30. Ce varianta preferi?')
            ->assertJsonStructure(['message', 'conversation_id']);
    }

    public function test_chat_can_check_preferred_availability_time(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'checkAvailability',
                                'args' => [
                                    'location_id' => '1',
                                    'service_id' => '1',
                                    'date' => '2026-05-05',
                                    'preferred_time' => '18:00',
                                ],
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $salon = $this->createSalon();
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['tue' => '10:00 - 19:00'],
        ]);
        $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);

        $response = $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [
                ['role' => 'assistant', 'content' => 'Pentru marti, am gasit sloturi la 10:00, 10:30 si 11:00.'],
                ['role' => 'user', 'content' => 'Vreau totusi la ora 18'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Ora 18:00 este disponibila pentru marți, 5 mai. Vrei sa continui cu aceasta ora?')
            ->assertJsonStructure(['message', 'conversation_id']);
    }

    public function test_existing_booking_conversation_does_not_create_duplicate_booking(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $salon = $this->createSalon();
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['tue' => '10:00 - 18:00'],
        ]);
        $service = $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
        $booking = $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Ion Pop',
            'client_phone' => '0700000000',
            'date' => '2026-05-05',
            'time' => '10:00',
            'status' => 'pending',
            'source' => 'ai_assistant',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => 'chat',
            'status' => 'completed',
            'intent' => 'booking',
            'summary' => 'Booking created.',
            'last_message_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'bookBooking',
                                'args' => [
                                    'client_name' => 'Ion Pop',
                                    'client_phone' => '0700000000',
                                    'location_id' => (string) $location->id,
                                    'service_id' => (string) $service->id,
                                    'date' => '2026-05-05',
                                    'time' => '10:00',
                                ],
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson("/assistant/{$salon->id}/chat", [
            'conversation_id' => $conversation->id,
            'messages' => [
                ['role' => 'user', 'content' => 'Multumesc mult'],
            ],
        ])->assertOk()
            ->assertJsonPath('message', 'Pentru o programare noua, te rugam sa apesi pe + si sa incepi o conversatie noua.')
            ->assertJsonPath('booking.id', $booking->id);

        $this->assertSame(1, $salon->bookings()->count());
    }

    public function test_existing_booking_conversation_does_not_check_availability_for_new_booking(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $salon = $this->createSalon();
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['tue' => '10:00 - 18:00'],
        ]);
        $service = $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
        $booking = $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Ion Pop',
            'client_phone' => '0700000000',
            'date' => '2026-05-05',
            'time' => '10:00',
            'status' => 'pending',
            'source' => 'ai_assistant',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => 'chat',
            'status' => 'completed',
            'intent' => 'booking',
            'summary' => 'Booking created.',
            'last_message_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'checkAvailability',
                                'args' => [
                                    'location_id' => (string) $location->id,
                                    'service_id' => (string) $service->id,
                                    'date' => '2026-05-05',
                                ],
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson("/assistant/{$salon->id}/chat", [
            'conversation_id' => $conversation->id,
            'messages' => [
                ['role' => 'user', 'content' => 'Mai vreau o programare marti'],
            ],
        ])->assertOk()
            ->assertJsonPath('message', 'Pentru o programare noua, te rugam sa apesi pe + si sa incepi o conversatie noua.')
            ->assertJsonPath('booking.id', $booking->id);

        $this->assertSame(1, $salon->bookings()->count());
    }

    private function createSalon(array $attributes = []): Salon
    {
        $user = User::factory()->create();

        return $user->salon()->create(array_merge([
            'name' => 'YouGo Studio',
        ], $attributes));
    }
}
