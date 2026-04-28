<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_business_name(): void
    {
        $response = $this->from('/register')->post('/register', $this->validRegistrationData([
            'business_name' => '',
        ]));

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('business_name');
        $this->assertGuest();
    }

    public function test_registration_requires_business_type(): void
    {
        $response = $this->from('/register')->post('/register', $this->validRegistrationData([
            'business_type' => '',
        ]));

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('business_type');
        $this->assertGuest();
    }

    public function test_user_registration_creates_appointment_salon_with_submitted_business_details(): void
    {
        $response = $this->post('/register', $this->validRegistrationData([
            'business_name' => 'Bright Care Clinic',
            'business_type' => 'clinic-healthcare',
        ]));

        $response->assertRedirect(route('dashboard.section', ['section' => 'onboarding']));
        $this->assertAuthenticated();

        $salon = Salon::query()->firstOrFail();

        $this->assertSame('Bright Care Clinic', $salon->name);
        $this->assertSame(Salon::MODE_APPOINTMENT, $salon->mode);
        $this->assertSame('clinic-healthcare', $salon->business_type);
        $this->assertNull($salon->industry);
        $this->assertFalse($salon->onboarding_completed);
    }

    public function test_registration_rejects_invalid_business_type(): void
    {
        $response = $this->from('/register')->post('/register', $this->validRegistrationData([
            'business_type' => 'invalid-type',
        ]));

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('business_type');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_no_longer_requires_industry(): void
    {
        $response = $this->post('/register', $this->validRegistrationData(['industry' => 'medical-clinic']));

        $response->assertRedirect(route('dashboard.section', ['section' => 'onboarding']));
        $this->assertDatabaseHas('salons', [
            'business_type' => 'salon-beauty',
            'industry' => null,
        ]);
    }

    public function test_registration_accepts_clinic_healthcare_without_industry(): void
    {
        $response = $this->post('/register', $this->validRegistrationData([
            'business_type' => 'clinic-healthcare',
        ]));

        $response->assertRedirect(route('dashboard.section', ['section' => 'onboarding']));
        $this->assertDatabaseHas('salons', [
            'business_type' => 'clinic-healthcare',
            'industry' => null,
        ]);
    }

    public function test_existing_login_flow_still_authenticates_users(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
        ]);

        $response = $this->post('/login', [
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    private function validRegistrationData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Alex Owner',
            'email' => 'alex@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'business_name' => 'Alex Studio',
            'business_type' => 'salon-beauty',
        ], $overrides);
    }
}
