<?php

namespace Tests\Feature;

use App\Enums\RequestPriority;
use App\Enums\RequestType;
use App\Models\CustomerRequest;
use App\Models\Salon;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_can_be_created_with_valid_fields(): void
    {
        $salon = $this->createSalon();

        $request = $salon->customerRequests()->create([
            'channel' => 'website',
            'type' => RequestType::Quote->value,
            'priority' => RequestPriority::Normal->value,
            'title' => 'Cerere oferta gard',
            'client_name' => 'Ion Popescu',
            'client_phone' => '0700000000',
            'idempotency_key' => 'salon:'.$salon->id.':conversation:1:request',
        ])->refresh();

        $this->assertSame('new', $request->status->value);
        $this->assertSame('quote', $request->type->value);
        $this->assertSame($salon->id, $request->salon_id);
    }

    public function test_request_does_not_require_booking_fields(): void
    {
        $salon = $this->createSalon();

        // No date/time/location/service — a Request must not need what a Booking needs.
        $request = $salon->customerRequests()->create([
            'channel' => 'whatsapp',
            'idempotency_key' => 'salon:'.$salon->id.':conversation:2:request',
        ])->refresh();

        $this->assertNull($request->location_id);
        $this->assertNull($request->service_id);
        $this->assertSame('general', $request->type->value);
    }

    public function test_status_transitions_through_lifecycle(): void
    {
        $request = $this->createRequest();

        foreach (['in_progress', 'contacted', 'resolved', 'closed'] as $status) {
            $request->update(['status' => $status]);
            $this->assertSame($status, $request->refresh()->status->value);
        }
    }

    public function test_priorities_are_normal_high_or_urgent(): void
    {
        $request = $this->createRequest();

        $request->update(['priority' => RequestPriority::Urgent->value]);

        $this->assertSame('urgent', $request->refresh()->priority->value);
    }

    public function test_urgent_is_a_priority_not_a_type(): void
    {
        $request = $this->createRequest(['priority' => RequestPriority::Urgent->value]);

        $this->assertSame('urgent', $request->priority->value);
        $this->assertNotSame('urgent', $request->type->value);
        $this->assertContains($request->type->value, RequestType::values());
    }

    public function test_quote_job_and_callback_are_request_types(): void
    {
        foreach (['quote', 'job', 'callback', 'diagnostic', 'information'] as $type) {
            $request = $this->createRequest(['type' => $type]);
            $this->assertSame($type, $request->type->value);
        }
    }

    public function test_tenant_cannot_list_another_tenants_requests(): void
    {
        $salonA = $this->createSalon();
        $salonB = $this->createSalon();
        $this->createRequest([], $salonA);
        $this->createRequest([], $salonB);

        $this->assertSame(1, CustomerRequest::query()->forSalon($salonA->id)->count());
        $this->assertSame(1, CustomerRequest::query()->forSalon($salonB->id)->count());
    }

    public function test_tenant_cannot_update_another_tenants_request(): void
    {
        $ownerA = User::factory()->create();
        $salonA = Salon::query()->create(['user_id' => $ownerA->id, 'name' => 'Salon A']);
        $salonB = $this->createSalon();
        $request = $this->createRequest([], $salonB);

        $response = $this->actingAs($ownerA)->patch("/customer-requests/{$request->id}", [
            'status' => 'resolved',
        ]);

        $response->assertForbidden();
        $this->assertSame('new', $request->refresh()->status->value);
    }

    public function test_owner_can_update_status_and_priority_via_id_guessing_is_blocked(): void
    {
        $owner = User::factory()->create();
        $ownSalon = Salon::query()->create(['user_id' => $owner->id, 'name' => 'Own Salon']);
        $ownRequest = $this->createRequest([], $ownSalon);
        $otherRequest = $this->createRequest();

        $this->actingAs($owner)
            ->patch("/customer-requests/{$ownRequest->id}", ['status' => 'contacted'])
            ->assertRedirect();
        $this->assertSame('contacted', $ownRequest->refresh()->status->value);

        $this->actingAs($owner)
            ->patch("/customer-requests/{$otherRequest->id}", ['status' => 'contacted'])
            ->assertForbidden();
    }

    public function test_owner_can_assign_staff_from_their_own_salon_only(): void
    {
        $owner = User::factory()->create();
        $salon = Salon::query()->create(['user_id' => $owner->id, 'name' => 'Own Salon']);
        $location = $salon->locations()->create(['name' => 'HQ', 'address' => 'Main St']);
        $staff = $salon->staff()->create(['location_id' => $location->id, 'name' => 'Ana', 'role' => 'Tech', 'active' => true]);
        $request = $this->createRequest([], $salon);

        $otherStaff = Staff::query()->create([
            'salon_id' => $this->createSalon()->id,
            'location_id' => $this->createSalon()->locations()->create(['name' => 'X', 'address' => 'Y'])->id,
            'name' => 'Other',
            'role' => 'Tech',
            'active' => true,
        ]);

        $this->actingAs($owner)
            ->patch("/customer-requests/{$request->id}", ['assignee_staff_id' => $staff->id])
            ->assertRedirect();
        $this->assertSame($staff->id, $request->refresh()->assignee_staff_id);

        $this->actingAs($owner)
            ->patch("/customer-requests/{$request->id}", ['assignee_staff_id' => $otherStaff->id])
            ->assertStatus(422);
    }

    public function test_retry_with_same_idempotency_key_does_not_create_duplicate(): void
    {
        $salon = $this->createSalon();
        $key = 'salon:'.$salon->id.':conversation:5:request';

        $salon->customerRequests()->create(['channel' => 'website', 'idempotency_key' => $key]);

        $this->expectException(QueryException::class);
        $salon->customerRequests()->create(['channel' => 'website', 'idempotency_key' => $key]);
    }

    public function test_required_fields_validation(): void
    {
        $salon = $this->createSalon();

        $this->expectException(QueryException::class);
        DB::table('customer_requests')->insert([
            'salon_id' => $salon->id,
            // missing required idempotency_key and channel
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSalon(array $attributes = []): Salon
    {
        $user = User::factory()->create();

        return Salon::query()->create(array_merge([
            'user_id' => $user->id,
            'name' => 'YouGo Studio',
        ], $attributes));
    }

    private function createRequest(array $attributes = [], ?Salon $salon = null): CustomerRequest
    {
        $salon ??= $this->createSalon();
        static $counter = 0;
        $counter++;

        return $salon->customerRequests()->create(array_merge([
            'channel' => 'website',
            'idempotency_key' => 'salon:'.$salon->id.':conversation:'.$counter.':request',
        ], $attributes))->refresh();
    }
}
