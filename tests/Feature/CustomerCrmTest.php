<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Assistant\CustomerBookingContextService;
use App\Services\Assistant\GeminiPayloadBuilder;
use App\Services\CRM\CustomerIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerCrmTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_schema_and_relationships_exist(): void
    {
        $this->assertTrue(Schema::hasTable('customers'));
        foreach (['salon_id', 'name', 'phone', 'phone_normalized', 'email', 'email_normalized', 'first_seen_at', 'last_seen_at', 'notes', 'metadata'] as $column) {
            $this->assertTrue(Schema::hasColumn('customers', $column));
        }
        $this->assertTrue(Schema::hasColumn('bookings', 'customer_id'));
        $this->assertTrue(Schema::hasColumn('conversations', 'customer_id'));

        $salon = $this->createSalon();
        $customer = $salon->customers()->create([
            'name' => 'Ana Pop',
            'phone' => '+40711111111',
            'phone_normalized' => '40711111111',
        ]);
        $booking = $salon->bookings()->create($this->bookingPayload(['customer_id' => $customer->id]));
        $conversation = $salon->conversations()->create([
            'customer_id' => $customer->id,
            'channel' => 'web_widget',
            'contact_phone' => '+40711111111',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);

        $this->assertTrue($customer->bookings->contains($booking));
        $this->assertTrue($customer->conversations->contains($conversation));
    }

    public function test_same_salon_same_normalized_phone_merges_into_one_customer(): void
    {
        $salon = $this->createSalon();
        $identity = app(CustomerIdentityService::class);

        $first = $salon->bookings()->create($this->bookingPayload(['client_phone' => 'whatsapp:+40 711 111 111']));
        $second = $salon->bookings()->create($this->bookingPayload(['client_name' => 'Ana P', 'client_phone' => '0040711111111']));

        $identity->identifyFromBooking($first);
        $identity->identifyFromBooking($second);

        $this->assertSame(1, $salon->customers()->count());
        $this->assertSame($first->refresh()->customer_id, $second->refresh()->customer_id);
    }

    public function test_same_phone_in_different_salons_stays_separate(): void
    {
        $firstSalon = $this->createSalon();
        $secondSalon = $this->createSalon();
        $identity = app(CustomerIdentityService::class);

        $first = $firstSalon->bookings()->create($this->bookingPayload(['client_phone' => '+40711111111']));
        $second = $secondSalon->bookings()->create($this->bookingPayload(['client_phone' => '+40711111111']));

        $identity->identifyFromBooking($first);
        $identity->identifyFromBooking($second);

        $this->assertNotSame($first->refresh()->customer_id, $second->refresh()->customer_id);
        $this->assertSame(1, $firstSalon->customers()->count());
        $this->assertSame(1, $secondSalon->customers()->count());
    }

    public function test_email_only_records_merge_and_name_only_does_not_create_customer(): void
    {
        $salon = $this->createSalon();
        $identity = app(CustomerIdentityService::class);

        $first = $salon->conversations()->create([
            'channel' => 'web_widget',
            'contact_name' => 'Maria',
            'contact_email' => 'Maria@Example.com',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);
        $second = $salon->conversations()->create([
            'channel' => 'web_widget',
            'contact_name' => 'Maria I',
            'contact_email' => 'maria@example.com',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);
        $nameOnly = $salon->conversations()->create([
            'channel' => 'web_widget',
            'contact_name' => 'Maria',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);

        $identity->identifyFromConversation($first);
        $identity->identifyFromConversation($second);
        $identity->identifyFromConversation($nameOnly);

        $this->assertSame(1, $salon->customers()->count());
        $this->assertSame($first->refresh()->customer_id, $second->refresh()->customer_id);
        $this->assertNull($nameOnly->refresh()->customer_id);
    }

    public function test_conflicting_phone_and_email_uses_phone_as_source_of_truth(): void
    {
        $salon = $this->createSalon();
        $identity = app(CustomerIdentityService::class);

        $emailOnly = $salon->conversations()->create([
            'channel' => 'web_widget',
            'contact_email' => 'shared@example.com',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);
        $phoneOwner = $salon->bookings()->create($this->bookingPayload(['client_phone' => '+40722222222']));
        $conflict = $salon->conversations()->create([
            'channel' => 'whatsapp',
            'contact_phone' => '+40722222222',
            'contact_email' => 'shared@example.com',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);

        $identity->identifyFromConversation($emailOnly);
        $identity->identifyFromBooking($phoneOwner);
        $identity->identifyFromConversation($conflict);

        $this->assertSame($phoneOwner->refresh()->customer_id, $conflict->refresh()->customer_id);
        $this->assertNotSame($emailOnly->refresh()->customer_id, $conflict->customer_id);
    }

    public function test_customers_dashboard_lists_profiles_from_existing_history(): void
    {
        $user = User::factory()->create();
        $salon = $this->createSalon(['user_id' => $user->id]);
        $salon->bookings()->create($this->bookingPayload([
            'client_name' => 'Ana Pop',
            'client_phone' => '+40711111111',
            'status' => 'completed',
        ]));
        $salon->conversations()->create([
            'channel' => 'whatsapp',
            'contact_name' => 'Ana Pop',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'open',
            'intent' => 'inquiry',
            'summary' => 'Asked about availability.',
            'last_message_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/dashboard/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('section', 'customers')
                ->where('crm.items.0.name', 'Ana Pop')
                ->where('crm.items.0.bookings_count', 1)
                ->where('crm.items.0.conversations_count', 1));
    }

    public function test_customer_detail_is_scoped_and_contains_history_preferences(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $salon = $this->createSalon(['user_id' => $user->id]);
        $otherSalon = $this->createSalon(['user_id' => $otherUser->id]);
        $service = $salon->services()->create(['name' => 'Tuns', 'price' => '100', 'duration' => 30]);
        $staff = $salon->staff()->create(['name' => 'Ioana']);

        foreach (range(1, 2) as $index) {
            $booking = $salon->bookings()->create($this->bookingPayload([
                'service_id' => $service->id,
                'staff_id' => $staff->id,
                'client_name' => 'Ana Pop',
                'client_phone' => '+40711111111',
                'status' => $index === 1 ? 'completed' : 'confirmed',
            ]));
            app(CustomerIdentityService::class)->identifyFromBooking($booking);
        }

        $customer = $salon->customers()->firstOrFail();
        $salon->conversations()->create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'contact_phone' => '+40711111111',
            'status' => 'open',
            'intent' => 'inquiry',
            'summary' => 'Asked for a new appointment.',
            'last_message_at' => now(),
        ]);
        $otherCustomer = $otherSalon->customers()->create(['phone' => '+40711111111', 'phone_normalized' => '40711111111']);

        $this->actingAs($user)
            ->get("/dashboard/customers/{$customer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('section', 'customer-detail')
                ->where('crm.customer.name', 'Ana Pop')
                ->where('crm.stats.total_bookings', 2)
                ->where('crm.preferences.service', 'Tuns')
                ->where('crm.preferences.staff', 'Ioana')
                ->where('crm.highlights.next_upcoming_booking.service.name', 'Tuns')
                ->where('crm.highlights.last_booking.service.name', 'Tuns')
                ->has('crm.bookings', 2)
                ->has('crm.conversations', 1));

        $this->actingAs($user)
            ->get("/dashboard/customers/{$otherCustomer->id}")
            ->assertNotFound();
    }

    public function test_business_user_can_update_and_clear_own_customer_notes(): void
    {
        $user = User::factory()->create();
        $salon = $this->createSalon(['user_id' => $user->id]);
        $customer = $salon->customers()->create([
            'name' => 'Ana Pop',
            'phone' => '+40711111111',
            'phone_normalized' => '40711111111',
        ]);

        $this->actingAs($user)
            ->patch("/dashboard/customers/{$customer->id}/notes", [
                'notes' => 'Prefers morning appointments.',
            ])
            ->assertRedirect();

        $this->assertSame('Prefers morning appointments.', $customer->refresh()->notes);

        $this->actingAs($user)
            ->patch("/dashboard/customers/{$customer->id}/notes", [
                'notes' => '   ',
            ])
            ->assertRedirect();

        $this->assertNull($customer->refresh()->notes);
    }

    public function test_customer_notes_validation_and_cross_salon_access(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $salon = $this->createSalon(['user_id' => $user->id]);
        $otherSalon = $this->createSalon(['user_id' => $otherUser->id]);
        $customer = $salon->customers()->create(['phone' => '+40711111111', 'phone_normalized' => '40711111111']);
        $otherCustomer = $otherSalon->customers()->create(['phone' => '+40722222222', 'phone_normalized' => '40722222222']);

        $this->actingAs($user)
            ->patch("/dashboard/customers/{$customer->id}/notes", [
                'notes' => str_repeat('a', 5001),
            ])
            ->assertSessionHasErrors('notes');

        $this->actingAs($user)
            ->patch("/dashboard/customers/{$otherCustomer->id}/notes", [
                'notes' => 'Should not save.',
            ])
            ->assertNotFound();
    }

    public function test_customers_dashboard_searches_by_name_phone_and_email(): void
    {
        $user = User::factory()->create();
        $salon = $this->createSalon(['user_id' => $user->id]);
        $salon->customers()->create([
            'name' => 'Ana Pop',
            'phone' => '+40711111111',
            'phone_normalized' => '40711111111',
            'email' => 'ana@example.com',
            'email_normalized' => 'ana@example.com',
            'last_seen_at' => now(),
        ]);
        $salon->customers()->create([
            'name' => 'Mihai Ionescu',
            'phone' => '+40722222222',
            'phone_normalized' => '40722222222',
            'email' => 'mihai@example.com',
            'email_normalized' => 'mihai@example.com',
            'last_seen_at' => now()->subDay(),
        ]);

        foreach ([
            'Ana Pop' => 'Ana Pop',
            '40722222222' => 'Mihai Ionescu',
            'mihai@example.com' => 'Mihai Ionescu',
        ] as $search => $expectedName) {
            $this->actingAs($user)
                ->get('/dashboard/customers?search='.urlencode($search))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Dashboard/Index')
                    ->where('section', 'customers')
                    ->where('crm.items.0.name', $expectedName)
                    ->has('crm.items', 1));
        }
    }

    public function test_ai_prompt_does_not_include_crm_notes_or_profile_data(): void
    {
        $salon = $this->createSalon();
        $customer = $salon->customers()->create([
            'name' => 'Private CRM Name',
            'phone' => '+40711111111',
            'phone_normalized' => '40711111111',
            'notes' => 'VIP internal allergy note never send to AI.',
        ]);
        $conversation = $salon->conversations()->create([
            'customer_id' => $customer->id,
            'channel' => 'web_widget',
            'contact_phone' => '+40711111111',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Buna'],
        ], $conversation);

        $encoded = json_encode($payload);

        $this->assertStringNotContainsString('VIP internal allergy note never send to AI.', $encoded);
        $this->assertStringNotContainsString('Private CRM Name', $encoded);
    }

    public function test_customers_require_auth_and_business_login_still_works(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->get('/dashboard/customers')->assertRedirect('/login');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard/customers');
    }

    public function test_whatsapp_recent_booking_context_still_matches_by_phone(): void
    {
        $salon = $this->createSalon();
        $salon->bookings()->create($this->bookingPayload([
            'client_phone' => '+40711111111',
            'status' => 'confirmed',
        ]));
        $conversation = $salon->conversations()->create([
            'channel' => 'whatsapp',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);

        $context = app(CustomerBookingContextService::class)->findRecentForCustomer($salon, $conversation, [
            'channel' => 'whatsapp',
            'phone' => 'whatsapp:+40711111111',
        ]);

        $this->assertSame('confirmed', $context['status']);
        $this->assertSame('+40711111111', $context['client_phone']);
    }

    public function test_dashboard_source_contains_customers_and_platform_admin_does_not(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard/Index.tsx'));
        $platformAdmin = file_get_contents(resource_path('js/Pages/PlatformAdmin/Components.tsx'));

        $this->assertStringContainsString('/dashboard/customers', $dashboard);
        $this->assertStringContainsString('CustomerDetail', $dashboard);
        $this->assertStringNotContainsString('CRM Light', $platformAdmin);
    }

    private function createSalon(array $attributes = []): Salon
    {
        $user = isset($attributes['user_id']) ? null : User::factory()->create();

        return Salon::query()->create(array_merge([
            'user_id' => $user?->id,
            'name' => 'YouGo Studio',
        ], $attributes));
    }

    private function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'client_name' => 'Ana Pop',
            'client_phone' => '+40711111111',
            'date' => now()->addDay()->toDateString(),
            'time' => '10:00',
            'status' => 'pending',
        ], $overrides);
    }
}
