<?php

namespace Tests\Feature;

use App\Mail\NewAiBookingMail;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WidgetEmbedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 4, 28, 9, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_salon_gets_widget_key(): void
    {
        $salon = $this->createSalon();

        $this->assertNotEmpty($salon->widget_key);
        $this->assertSame(40, strlen($salon->widget_key));
        $this->assertTrue($salon->widget_enabled);
    }

    public function test_existing_salon_with_missing_widget_key_can_get_one(): void
    {
        $salon = $this->createSalon();
        $salon->forceFill(['widget_key' => null])->save();

        $this->assertSame(40, strlen($salon->refresh()->ensureWidgetKey()));
    }

    public function test_widget_keys_are_unique(): void
    {
        $first = $this->createSalon();
        $second = $this->createSalon();

        $this->assertNotSame($first->widget_key, $second->widget_key);
    }

    public function test_widget_script_and_page_load(): void
    {
        $salon = $this->createSalon();

        $this->get("/widget/{$salon->widget_key}.js")
            ->assertOk()
            ->assertHeader('content-type', 'application/javascript; charset=UTF-8')
            ->assertSee("/widget/{$salon->widget_key}", false);

        $this->get("/widget/{$salon->widget_key}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Widget/Show')
                ->where('salon.id', $salon->id)
                ->where('chatEndpoint', route('widget.chat', $salon->widget_key))
            );
    }

    public function test_invalid_or_disabled_widget_returns_not_found(): void
    {
        $salon = $this->createSalon(['widget_enabled' => false]);

        $this->get('/widget/invalid-key')->assertNotFound();
        $this->get("/widget/{$salon->widget_key}")->assertNotFound();
        $this->postJson("/widget/{$salon->widget_key}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Buna']],
        ])->assertNotFound();
    }

    public function test_widget_chat_creates_and_reuses_web_widget_conversation(): void
    {
        config(['services.gemini.key' => null]);
        $salon = $this->createSalon();

        $first = $this->postJson("/widget/{$salon->widget_key}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Buna']],
        ]);

        $first->assertStatus(503)->assertJsonStructure(['message', 'conversation_id']);
        $conversationId = $first->json('conversation_id');

        $second = $this->postJson("/widget/{$salon->widget_key}/chat", [
            'conversation_id' => $conversationId,
            'messages' => [
                ['role' => 'user', 'content' => 'Buna'],
                ['role' => 'assistant', 'content' => 'Salut'],
                ['role' => 'user', 'content' => 'Vreau o programare'],
            ],
        ]);

        $second->assertStatus(503)->assertJsonPath('conversation_id', $conversationId);
        $this->assertSame('web_widget', $salon->conversations()->first()->channel);
        $this->assertSame(1, $salon->conversations()->count());
    }

    public function test_widget_chat_accepts_known_contact(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Sure, I can use those details.']]],
            ]],
        ], 200)]);
        $salon = $this->createSalon();

        $this->postJson("/widget/{$salon->widget_key}/chat", [
            'messages' => [
                ['role' => 'assistant', 'content' => 'Would you like to use the previously used contact details for this booking as well: Daniel, 07123 456789?'],
                ['role' => 'user', 'content' => 'Yes'],
            ],
            'known_contact' => [
                'name' => 'Daniel',
                'phone' => '07123 456789',
            ],
        ])->assertOk()
            ->assertJsonPath('message', 'Sure, I can use those details.');

        Http::assertSent(function ($request) {
            $instruction = $request->data()['systemInstruction']['parts'][0]['text'] ?? '';

            return str_contains($instruction, 'Previous contact details for this browser visitor are available: Daniel, 07123 456789.')
                && str_contains($instruction, 'If they confirm, you may use them as client_name and client_phone for bookBooking.');
        });
    }

    public function test_allowed_domains_empty_allows_chat_and_configured_domains_are_enforced(): void
    {
        config(['services.gemini.key' => null]);
        $salon = $this->createSalon();

        $this->postJson("/widget/{$salon->widget_key}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Buna']],
        ])->assertStatus(503);

        $salon->update(['widget_allowed_domains' => ['example.com']]);

        $this->withHeader('Origin', 'https://www.example.com')
            ->postJson("/widget/{$salon->widget_key}/chat", [
                'messages' => [['role' => 'user', 'content' => 'Buna']],
            ])->assertStatus(503);

        $this->withHeader('Origin', 'https://evil.test')
            ->postJson("/widget/{$salon->widget_key}/chat", [
                'messages' => [['role' => 'user', 'content' => 'Buna']],
            ])->assertForbidden();
    }

    public function test_preview_assistant_page_still_loads(): void
    {
        $salon = $this->createSalon();

        $this->get("/assistant/{$salon->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Assistant/Show'));
    }

    public function test_dashboard_widget_section_contains_preview_and_embed_code(): void
    {
        $user = User::factory()->create();
        $salon = $user->salon()->create(['name' => 'YouGo Studio']);

        $this->actingAs($user)
            ->get('/dashboard/widget')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->where('section', 'widget')
                ->where('salon.id', $salon->id)
                ->where('salon.widget_key', $salon->widget_key)
            );
    }

    public function test_authenticated_user_can_update_widget_settings(): void
    {
        $user = User::factory()->create();
        $salon = $user->salon()->create(['name' => 'YouGo Studio']);

        $this->actingAs($user)->put('/widget-settings', [
            'widget_enabled' => true,
            'widget_allowed_domains' => ['https://Example.com', 'www.example.com', 'client.test'],
            'widget_primary_color' => '#111827',
            'widget_position' => 'bottom-left',
        ])->assertRedirect();

        $salon->refresh();
        $this->assertSame(['example.com', 'client.test'], $salon->widget_allowed_domains);
        $this->assertSame('#111827', $salon->widget_primary_color);
        $this->assertSame('bottom-left', $salon->widget_position);
    }

    public function test_user_updates_only_their_own_widget_settings(): void
    {
        $user = User::factory()->create();
        $ownSalon = $user->salon()->create(['name' => 'Own Studio']);
        $otherSalon = $this->createSalon(['widget_primary_color' => '#2563eb']);

        $this->actingAs($user)->put('/widget-settings', [
            'widget_enabled' => false,
            'widget_allowed_domains' => [],
            'widget_primary_color' => '#000000',
            'widget_position' => 'bottom-right',
        ])->assertRedirect();

        $this->assertFalse($ownSalon->refresh()->widget_enabled);
        $this->assertSame('#2563eb', $otherSalon->refresh()->widget_primary_color);
    }

    public function test_widget_chat_can_create_booking_and_send_notification(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Mail::fake();

        $salon = $this->createSalon([
            'notification_email' => 'owner@example.com',
            'email_notifications' => true,
            'booking_confirmations' => true,
        ]);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['wed' => '10:00 - 18:00'],
        ]);
        $service = $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'bookBooking',
                                'args' => [
                                    'client_name' => 'Ana Pop',
                                    'client_phone' => '0700000000',
                                    'location_id' => (string) $location->id,
                                    'service_id' => (string) $service->id,
                                    'date' => '2026-04-29',
                                    'time' => '10:00',
                                ],
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $response = $this->postJson("/widget/{$salon->widget_key}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Vreau o programare']],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Am înregistrat cererea de programare pentru miercuri, 29 aprilie, la ora 10:00. Echipa te va contacta pentru confirmare.')
            ->assertJsonStructure(['message', 'conversation_id', 'booking']);
        $booking = $salon->bookings()->firstOrFail();
        $this->assertSame('ai_assistant', $booking->source);
        $this->assertNotNull($booking->notification_sent_at);
        Mail::assertSent(NewAiBookingMail::class);
    }

    private function createSalon(array $attributes = []): Salon
    {
        $user = User::factory()->create();

        return $user->salon()->create(array_merge([
            'name' => 'YouGo Studio',
        ], $attributes));
    }
}
