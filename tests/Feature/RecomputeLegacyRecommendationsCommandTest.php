<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecomputeLegacyRecommendationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_recomputes_recommendations_for_unconfirmed_salons_only(): void
    {
        $user = User::factory()->create();
        $legacy = Salon::query()->create([
            'user_id' => $user->id,
            'name' => 'Legacy Plumber',
            'mode' => Salon::MODE_LEAD,
            'business_type' => 'home-services',
        ]);
        $this->assertSame('default', $legacy->capabilities_source);
        $this->assertNull($legacy->recommended_primary_capability);

        $confirmed = Salon::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Existing Salon',
            'business_type' => 'salon-beauty',
        ]);

        $this->artisan('business:recompute-legacy-recommendations')->assertSuccessful();

        $legacy->refresh();
        $this->assertSame('request', $legacy->recommended_primary_capability);
        $this->assertNull($legacy->primary_capability, 'recompute must not touch active capabilities');
        $this->assertSame('default', $legacy->capabilities_source);

        $confirmed->refresh();
        $this->assertNull($confirmed->recommended_primary_capability, 'already-confirmed salons must be left alone');
    }
}
