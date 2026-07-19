<?php

namespace Tests\Feature\Onboarding;

use App\DataTransferObjects\Onboarding\NormalizedExtractionResult;
use App\DataTransferObjects\Onboarding\OnboardingAnalysisResult;
use App\Enums\OnboardingDraftStatus;
use App\Jobs\AnalyzeOnboardingDraftJob;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\Analyzer\FakeOnboardingSourceAnalyzer;
use App\Services\Onboarding\Analyzer\OnboardingSourceAnalyzer;
use App\Services\Onboarding\OnboardingDraftStateMachine;
use App\Services\Onboarding\OnboardingEntityDeduplicator;
use App\Services\Onboarding\OnboardingStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnboardingRawResultRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_small_raw_result_is_stored_inline(): void
    {
        Storage::fake('local');
        config(['onboarding.raw_result.max_inline_bytes' => 65536]);

        $analyzer = (new FakeOnboardingSourceAnalyzer)->willReturn(
            fn ($draft) => new OnboardingAnalysisResult(
                raw: ['note' => 'small payload'],
                normalized: FakeOnboardingSourceAnalyzer::defaultResult($draft)->normalized,
                schemaVersion: NormalizedExtractionResult::CURRENT_SCHEMA_VERSION,
            )
        );
        $this->app->instance(OnboardingSourceAnalyzer::class, $analyzer);

        $draft = $this->createPendingDraft();
        $this->runJob($draft);

        $draft->refresh();
        $this->assertNotNull($draft->raw_extraction_result);
        $this->assertNull($draft->raw_result_storage_path);
        $this->assertNotNull($draft->raw_result_checksum);
        $this->assertGreaterThan(0, $draft->raw_result_size_bytes);
    }

    public function test_oversized_raw_result_is_stored_on_disk_with_a_reference(): void
    {
        Storage::fake('local');
        config(['onboarding.raw_result.max_inline_bytes' => 10]); // force overflow

        $analyzer = (new FakeOnboardingSourceAnalyzer)->willReturn(
            fn ($draft) => new OnboardingAnalysisResult(
                raw: ['note' => str_repeat('a', 200)],
                normalized: FakeOnboardingSourceAnalyzer::defaultResult($draft)->normalized,
                schemaVersion: NormalizedExtractionResult::CURRENT_SCHEMA_VERSION,
            )
        );
        $this->app->instance(OnboardingSourceAnalyzer::class, $analyzer);

        $draft = $this->createPendingDraft();
        $this->runJob($draft);

        $draft->refresh();
        $this->assertNull($draft->raw_extraction_result);
        $this->assertNotNull($draft->raw_result_storage_path);
        Storage::disk('local')->assertExists($draft->raw_result_storage_path);
        $this->assertStringContainsString((string) $draft->id, $draft->raw_result_storage_path);
    }

    private function runJob(OnboardingDraft $draft): void
    {
        (new AnalyzeOnboardingDraftJob($draft->id))->handle(
            $this->app->make(OnboardingSourceAnalyzer::class),
            $this->app->make(OnboardingEntityDeduplicator::class),
            $this->app->make(OnboardingStateMachine::class),
            $this->app->make(OnboardingDraftStateMachine::class),
        );
    }

    private function createPendingDraft(): OnboardingDraft
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => 'Test Salon']);

        return OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'http://93.184.216.34/',
            'normalized_source_url' => 'http://93.184.216.34/',
            'status' => OnboardingDraftStatus::Pending,
        ]);
    }
}
