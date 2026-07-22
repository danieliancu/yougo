<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\CustomerRequest;
use App\Models\Salon;
use App\Models\User;
use App\Services\Conversation\ConversationResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_can_produce_a_booking_result(): void
    {
        $salon = $this->createSalon();
        $conversation = $this->createConversation($salon);

        $booking = app(ConversationResultService::class)->createAndAttach(
            $conversation,
            Conversation::RESULT_TYPE_BOOKING,
            fn () => $salon->bookings()->create(['client_name' => 'Ana', 'date' => '2026-08-01', 'time' => '10:00', 'status' => 'pending']),
        );

        $conversation->refresh();
        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertSame('booking', $conversation->result_type);
        $this->assertSame($booking->id, $conversation->result_id);
        $this->assertSame($booking->id, $conversation->booking_id, 'legacy mirror must stay in sync');
        $this->assertInstanceOf(Booking::class, $conversation->result);
    }

    public function test_conversation_can_produce_a_request_result(): void
    {
        $salon = $this->createSalon();
        $conversation = $this->createConversation($salon);

        $request = app(ConversationResultService::class)->createAndAttach(
            $conversation,
            Conversation::RESULT_TYPE_CUSTOMER_REQUEST,
            fn () => $salon->customerRequests()->create(['channel' => 'website', 'idempotency_key' => 'k1']),
        );

        $conversation->refresh();
        $this->assertInstanceOf(CustomerRequest::class, $request);
        $this->assertSame('customer_request', $conversation->result_type);
        $this->assertSame($request->id, $conversation->result_id);
        $this->assertNull($conversation->booking_id, 'a request result must not touch the legacy booking_id mirror');
        $this->assertInstanceOf(CustomerRequest::class, $conversation->result);
        $this->assertTrue($request->sourceConversation()->exists());
    }

    public function test_conversation_cannot_have_two_results(): void
    {
        $salon = $this->createSalon();
        $conversation = $this->createConversation($salon);
        $service = app(ConversationResultService::class);

        $booking = $service->createAndAttach($conversation, Conversation::RESULT_TYPE_BOOKING, fn () => $salon->bookings()->create(['client_name' => 'Ana', 'date' => '2026-08-01', 'time' => '10:00', 'status' => 'pending']));
        $this->assertNotNull($booking);

        $created = false;
        $second = $service->createAndAttach($conversation, Conversation::RESULT_TYPE_CUSTOMER_REQUEST, function () use ($salon, &$created) {
            $created = true;

            return $salon->customerRequests()->create(['channel' => 'website', 'idempotency_key' => 'never-created']);
        });

        $this->assertNull($second);
        $this->assertFalse($created, 'the second create-callback must never run once a result already exists');
        $this->assertSame(0, CustomerRequest::query()->count());
    }

    public function test_old_conversations_with_only_booking_id_continue_to_work(): void
    {
        $salon = $this->createSalon();
        $booking = $salon->bookings()->create(['client_name' => 'Legacy', 'date' => '2026-08-01', 'time' => '09:00', 'status' => 'confirmed']);

        // Simulates a pre-migration row: booking_id set directly, bypassing the service,
        // as legacy code always did before this table had result_type/result_id.
        $conversation = $salon->conversations()->create([
            'channel' => 'chat', 'status' => 'completed', 'booking_id' => $booking->id,
        ]);

        $this->assertSame($booking->id, $conversation->booking->id);
    }

    public function test_backfill_migration_populates_result_columns_for_existing_booking_conversations(): void
    {
        $salon = $this->createSalon();
        $booking = $salon->bookings()->create(['client_name' => 'Legacy', 'date' => '2026-08-01', 'time' => '09:00', 'status' => 'confirmed']);

        $migration = require database_path('migrations/2026_07_22_000003_add_result_columns_to_conversations_table.php');
        $migration->down();

        $conversationId = \DB::table('conversations')->insertGetId([
            'salon_id' => $salon->id, 'channel' => 'chat', 'status' => 'completed', 'booking_id' => $booking->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $row = \DB::table('conversations')->find($conversationId);
        $this->assertSame('booking', $row->result_type);
        $this->assertSame($booking->id, $row->result_id);
    }

    public function test_disallowed_result_type_is_rejected_by_the_morph_map(): void
    {
        $salon = $this->createSalon();
        $conversation = $this->createConversation($salon);

        $this->expectException(\InvalidArgumentException::class);

        app(ConversationResultService::class)->createAndAttach(
            $conversation,
            'not_a_real_type',
            fn () => $salon->bookings()->create(['client_name' => 'Ana', 'date' => '2026-08-01', 'time' => '10:00', 'status' => 'pending']),
        );
    }

    public function test_retry_does_not_duplicate_a_result(): void
    {
        $salon = $this->createSalon();
        $conversation = $this->createConversation($salon);
        $service = app(ConversationResultService::class);

        $create = fn () => $salon->customerRequests()->create(['channel' => 'website', 'idempotency_key' => 'retry-key']);

        $first = $service->createAndAttach($conversation, Conversation::RESULT_TYPE_CUSTOMER_REQUEST, $create);
        $second = $service->createAndAttach($conversation, Conversation::RESULT_TYPE_CUSTOMER_REQUEST, $create);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, CustomerRequest::query()->count());
    }

    private function createSalon(): Salon
    {
        $user = User::factory()->create();

        return Salon::query()->create(['user_id' => $user->id, 'name' => 'YouGo Studio']);
    }

    private function createConversation(Salon $salon): Conversation
    {
        return $salon->conversations()->create(['channel' => 'chat', 'status' => 'open']);
    }
}
