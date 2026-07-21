<?php

namespace Tests\Feature\Onboarding;

use App\DataTransferObjects\Onboarding\ConfirmedSelections;
use App\Enums\OnboardingDraftStatus;
use App\Enums\OnboardingState;
use App\Exceptions\Onboarding\IncompleteOnboardingDraftException;
use App\Jobs\AnalyzeOnboardingDraftJob;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\OnboardingDraftConfirmationService;
use App\Services\Onboarding\OnboardingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class OnboardingImportManualFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_start_reaches_review_required_with_an_empty_result_and_dispatches_no_job(): void
    {
        Bus::fake();
        [$salon, $user] = $this->createSalonAndUser();

        $draft = $this->service()->start($salon, $user, 'manual', null);

        $this->assertSame(OnboardingDraftStatus::ReviewRequired, $draft->status);
        $this->assertSame(OnboardingState::ReviewRequired, $salon->refresh()->onboarding_state);
        $this->assertSame('1.0', $draft->normalized_extraction_result['schema_version']);
        $this->assertSame([], $draft->normalized_extraction_result['locations']);
        $this->assertSame([], $draft->normalized_extraction_result['services']);
        Bus::assertNotDispatched(AnalyzeOnboardingDraftJob::class);
    }

    public function test_adding_a_new_location_and_service_via_update_then_confirming_creates_them(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $draft = $this->service()->start($salon, $user, 'manual', null);

        $draft = $this->service()->updateDraft($draft, $draft->revision, [
            'business.name' => ['value' => 'Salon Manual'],
            'locations.new-1.name' => ['value' => 'Sediul Central'],
            'locations.new-1.address' => ['value' => 'Str. Exemplu 1'],
            'locations.new-1.opening_hours' => ['value' => ['mon' => '09:00 - 18:00']],
            'services.new-1.name' => ['value' => 'Tuns'],
            'services.new-1.category' => ['value' => 'coafor'],
            'services.new-1.price' => ['value' => '50'],
        ]);

        $salonAfter = $this->confirmationService()->confirm($draft->refresh(), $user, ConfirmedSelections::fromArray([
            'expected_revision' => $draft->revision,
        ]));

        $this->assertSame('Salon Manual', $salonAfter->name);
        $this->assertSame(1, $salonAfter->locations()->count());
        $this->assertSame('Sediul Central', $salonAfter->locations()->first()->name);
        $this->assertSame(1, $salonAfter->services()->count());
        $this->assertSame('Tuns', $salonAfter->services()->first()->name);
    }

    public function test_a_manual_draft_confirmed_without_a_service_or_location_is_rejected(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $draft = $this->service()->start($salon, $user, 'manual', null);

        $draft = $this->service()->updateDraft($draft, $draft->revision, [
            'business.name' => ['value' => 'Salon Manual'],
        ]);

        try {
            $this->confirmationService()->confirm($draft->refresh(), $user, ConfirmedSelections::fromArray([
                'expected_revision' => $draft->revision,
            ]));
            $this->fail('Expected IncompleteOnboardingDraftException.');
        } catch (IncompleteOnboardingDraftException $exception) {
            $this->assertContains('no_location_and_no_customer_service_area', $exception->failedConditions);
            $this->assertContains('no_services', $exception->failedConditions);
        }
    }

    private function service(): OnboardingImportService
    {
        return $this->app->make(OnboardingImportService::class);
    }

    private function confirmationService(): OnboardingDraftConfirmationService
    {
        return $this->app->make(OnboardingDraftConfirmationService::class);
    }

    /**
     * @return array{0: Salon, 1: User}
     */
    private function createSalonAndUser(): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => '']);

        return [$salon, $user];
    }
}
