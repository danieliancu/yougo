<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Dashboard\DashboardDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 4, 27, 12, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_overview_metrics_are_calculated_correctly(): void
    {
        $salon = $this->createSalon();
        $this->seedOverviewData($salon);

        $overview = app(DashboardDataService::class)->overview($salon);
        $metrics = $overview['metrics'];

        $this->assertSame(4, $metrics['total_conversations']);
        $this->assertSame(2, $metrics['conversations_today']);
        $this->assertSame(1, $metrics['open_conversations']);
        $this->assertSame(1, $metrics['abandoned_conversations']);
        $this->assertSame(4, $metrics['total_bookings']);
        $this->assertSame(1, $metrics['pending_bookings']);
        $this->assertSame(1, $metrics['confirmed_bookings']);
        $this->assertSame(1, $metrics['completed_bookings']);
        $this->assertSame(1, $metrics['bookings_today']);
        $this->assertSame(3, $metrics['bookings_this_week']);
        $this->assertSame(100.0, $metrics['conversion_rate']);
    }

    public function test_latest_conversations_and_bookings_are_included(): void
    {
        $salon = $this->createSalon();
        $this->seedOverviewData($salon);

        $overview = app(DashboardDataService::class)->overview($salon);

        $this->assertCount(4, $overview['latest_conversations']);
        $this->assertSame('Latest Visitor', $overview['latest_conversations']->first()->contact_name);
        $this->assertCount(4, $overview['latest_bookings']);
        $this->assertSame('Next Week', $overview['latest_bookings']->first()->client_name);
    }

    public function test_dashboard_still_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $user->salon()->create(['name' => 'YouGo Studio']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->has('salon')
                ->has('overview.metrics')
                ->has('overview.latest_conversations')
                ->has('overview.latest_bookings')
            );
    }

    private function createSalon(array $attributes = []): Salon
    {
        $user = User::factory()->create();

        return $user->salon()->create(array_merge([
            'name' => 'YouGo Studio',
            'timezone' => 'Europe/London',
        ], $attributes));
    }

    private function seedOverviewData(Salon $salon): void
    {
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
        ]);
        $service = $salon->services()->create([
            'name' => 'Consultatie',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);

        $bookings = [
            ['client_name' => 'Today Pending', 'date' => '2026-04-27', 'time' => '10:00', 'status' => 'pending'],
            ['client_name' => 'Tomorrow Confirmed', 'date' => '2026-04-28', 'time' => '11:00', 'status' => 'confirmed'],
            ['client_name' => 'Friday Completed', 'date' => '2026-05-01', 'time' => '12:00', 'status' => 'completed'],
            ['client_name' => 'Next Week', 'date' => '2026-05-04', 'time' => '09:00', 'status' => 'cancelled'],
        ];

        foreach ($bookings as $booking) {
            $salon->bookings()->create(array_merge($booking, [
                'location_id' => $location->id,
                'service_id' => $service->id,
                'client_phone' => '0700000000',
            ]));
        }

        $this->createConversation($salon, [
            'contact_name' => 'Old Visitor',
            'status' => 'completed',
            'intent' => 'booking',
            'created_at' => '2026-04-26 09:00:00',
            'updated_at' => '2026-04-26 09:00:00',
            'last_message_at' => '2026-04-26 09:10:00',
        ]);
        $this->createConversation($salon, [
            'contact_name' => 'Open Visitor',
            'status' => 'open',
            'intent' => 'inquiry',
            'created_at' => '2026-04-27 08:00:00',
            'updated_at' => '2026-04-27 08:00:00',
            'last_message_at' => '2026-04-27 08:05:00',
        ]);
        $this->createConversation($salon, [
            'contact_name' => 'Abandoned Visitor',
            'status' => 'completed',
            'intent' => 'abandoned',
            'created_at' => '2026-04-27 09:00:00',
            'updated_at' => '2026-04-27 09:00:00',
            'last_message_at' => '2026-04-27 09:05:00',
        ]);
        $this->createConversation($salon, [
            'contact_name' => 'Latest Visitor',
            'status' => 'completed',
            'intent' => 'booking',
            'created_at' => '2026-04-25 09:00:00',
            'updated_at' => '2026-04-25 09:00:00',
            'last_message_at' => '2026-04-27 11:00:00',
        ]);
    }

    private function createConversation(Salon $salon, array $attributes): void
    {
        $conversation = $salon->conversations()->create([
            'contact_name' => $attributes['contact_name'],
            'status' => $attributes['status'],
            'intent' => $attributes['intent'],
            'last_message_at' => $attributes['last_message_at'],
        ]);

        $conversation->forceFill([
            'created_at' => $attributes['created_at'],
            'updated_at' => $attributes['updated_at'],
        ])->save();
    }
}
