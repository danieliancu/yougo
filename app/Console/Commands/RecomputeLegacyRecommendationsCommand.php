<?php

namespace App\Console\Commands;

use App\Models\Salon;
use App\Services\Business\IndustryDefaultsService;
use Illuminate\Console\Command;

/**
 * Step 2 of the legacy `lead`/`reservation` mode backfill (see the
 * add_business_capabilities_to_salons_table migration for step 1): populates
 * `recommended_*` for salons the migration left on `capabilities_source = 'default'`
 * with no active capability. Deliberately a separate command rather than migration logic
 * — migrations must not resolve application services, since those can change shape over
 * time. Never touches active capabilities; only IndustryDefaultsService::confirm()
 * (triggered by an explicit user action) does that.
 */
class RecomputeLegacyRecommendationsCommand extends Command
{
    protected $signature = 'business:recompute-legacy-recommendations';

    protected $description = 'Populate recommended capabilities for salons left unconfirmed by the legacy mode backfill.';

    public function handle(IndustryDefaultsService $industryDefaults): int
    {
        $updated = 0;

        Salon::query()
            ->where('capabilities_source', Salon::CAPABILITIES_SOURCE_DEFAULT)
            ->whereNull('primary_capability')
            ->whereNotNull('business_type')
            ->chunkById(100, function ($salons) use ($industryDefaults, &$updated) {
                foreach ($salons as $salon) {
                    $industryDefaults->applyRecommendation($salon, $salon->business_type);
                    $updated++;
                }
            });

        $this->info("Recomputed recommendations for {$updated} salon(s).");

        return self::SUCCESS;
    }
}
