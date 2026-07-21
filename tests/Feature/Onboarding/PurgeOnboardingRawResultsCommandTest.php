<?php

namespace Tests\Feature\Onboarding;

use App\Console\Commands\PurgeOnboardingRawResultsCommand;
use App\Enums\OnboardingDraftStatus;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeOnboardingRawResultsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_draft_past_purge_after_is_left_untouched(): void
    {
        Storage::fake('local');
        $draft = $this->createDraft(OnboardingDraftStatus::ReviewRequired, purgeAfter: now()->subDay());

        $this->artisan(PurgeOnboardingRawResultsCommand::class)->assertSuccessful();

        $draft->refresh();
        $this->assertNotNull($draft->raw_extraction_result);
        $this->assertNull($draft->raw_result_purged_at);
    }

    public function test_confirmed_draft_with_a_storage_file_gets_it_deleted_and_marked_purged(): void
    {
        Storage::fake('local');
        $path = 'onboarding-raw/test-file.json';
        Storage::disk('local')->put($path, '{"note":"raw"}');

        $draft = $this->createDraft(
            OnboardingDraftStatus::Confirmed,
            purgeAfter: now()->subDay(),
            storagePath: $path,
        );

        $this->artisan(PurgeOnboardingRawResultsCommand::class)->assertSuccessful();

        $draft->refresh();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($draft->raw_result_storage_path);
        $this->assertNull($draft->raw_extraction_result);
        $this->assertNotNull($draft->raw_result_purged_at);
    }

    public function test_confirmed_draft_whose_file_is_already_missing_is_treated_as_purged(): void
    {
        Storage::fake('local');
        $draft = $this->createDraft(
            OnboardingDraftStatus::Confirmed,
            purgeAfter: now()->subDay(),
            storagePath: 'onboarding-raw/already-gone.json',
        );

        $this->artisan(PurgeOnboardingRawResultsCommand::class)->assertSuccessful();

        $draft->refresh();
        $this->assertNull($draft->raw_result_storage_path);
        $this->assertNotNull($draft->raw_result_purged_at);
    }

    public function test_confirmed_draft_whose_purge_after_is_in_the_future_is_untouched(): void
    {
        Storage::fake('local');
        $draft = $this->createDraft(OnboardingDraftStatus::Confirmed, purgeAfter: now()->addDay());

        $this->artisan(PurgeOnboardingRawResultsCommand::class)->assertSuccessful();

        $draft->refresh();
        $this->assertNotNull($draft->raw_extraction_result);
        $this->assertNull($draft->raw_result_purged_at);
    }

    public function test_already_purged_draft_is_skipped_on_a_second_run(): void
    {
        Storage::fake('local');
        $draft = $this->createDraft(OnboardingDraftStatus::Confirmed, purgeAfter: now()->subDay());

        $this->artisan(PurgeOnboardingRawResultsCommand::class)->assertSuccessful();
        $purgedAt = $draft->refresh()->raw_result_purged_at;

        $this->artisan(PurgeOnboardingRawResultsCommand::class)->assertSuccessful();

        $this->assertEquals($purgedAt, $draft->refresh()->raw_result_purged_at);
    }

    public function test_storage_delete_returning_false_leaves_the_draft_retryable_and_logs(): void
    {
        Log::shouldReceive('warning')->atLeast()->once();

        Storage::shouldReceive('disk')->andReturnUsing(function () {
            return new class
            {
                public function exists($path)
                {
                    return true;
                }

                public function delete($path)
                {
                    return false;
                }
            };
        });

        $draft = $this->createDraft(
            OnboardingDraftStatus::Confirmed,
            purgeAfter: now()->subDay(),
            storagePath: 'onboarding-raw/undeletable.json',
        );

        $this->artisan(PurgeOnboardingRawResultsCommand::class)->assertSuccessful();

        $draft->refresh();
        $this->assertNotNull($draft->raw_result_storage_path);
        $this->assertNull($draft->raw_result_purged_at);
    }

    public function test_a_disk_exception_is_caught_logged_and_does_not_stop_the_rest_of_the_batch(): void
    {
        Storage::fake('local');
        Log::shouldReceive('warning')->atLeast()->once();

        $failing = $this->createDraft(
            OnboardingDraftStatus::Confirmed,
            purgeAfter: now()->subDay(),
            storagePath: 'onboarding-raw/throws.json',
        );
        Storage::disk('local')->put($failing->raw_result_storage_path, 'x');

        $healthy = $this->createDraft(OnboardingDraftStatus::Confirmed, purgeAfter: now()->subDay());

        // Force an exception only for the failing draft's path by swapping the disk after seeding it.
        Storage::shouldReceive('disk')->with('local')->andReturnUsing(function () use ($failing) {
            return new class($failing->raw_result_storage_path)
            {
                public function __construct(private string $throwingPath) {}

                public function exists($path)
                {
                    return true;
                }

                public function delete($path)
                {
                    if ($path === $this->throwingPath) {
                        throw new \RuntimeException('disk unavailable');
                    }

                    return true;
                }
            };
        });

        $this->artisan(PurgeOnboardingRawResultsCommand::class)->assertSuccessful();

        $this->assertNull($failing->refresh()->raw_result_purged_at);
        $this->assertNotNull($healthy->refresh()->raw_result_purged_at);
    }

    private function createDraft(
        OnboardingDraftStatus $status,
        Carbon $purgeAfter,
        ?string $storagePath = null,
    ): OnboardingDraft {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => 'Test Salon']);

        return OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'https://example.ro',
            'normalized_source_url' => 'https://example.ro',
            'status' => $status,
            'raw_extraction_result' => $storagePath === null ? ['note' => 'inline raw'] : null,
            'raw_result_storage_path' => $storagePath,
            'raw_result_purge_after' => $purgeAfter,
        ]);
    }
}
