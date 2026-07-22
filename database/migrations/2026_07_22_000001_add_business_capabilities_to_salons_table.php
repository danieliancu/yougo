<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Business capabilities (appointment/request, reservation recognized-only) replace the
 * unused `mode` fork as the source of truth for what a salon's AI receptionist may do.
 *
 * Backfill is intentionally static/SQL-only here — no app services are resolved from a
 * migration, since services can change shape over time and migrations must stay stable.
 * `mode = 'appointment'` (or empty) salons keep their current, already-active behavior.
 * `mode = 'lead'` and `mode = 'reservation'` salons get NO capability auto-enabled: today
 * both produce the exact same generic "no booking tool" AI fallback (see
 * GeminiPayloadBuilder::modeInstructions()), so activating a real `request` tool for them
 * here would be a silent behavior change, not a rename. They're logged for audit and left
 * on `capabilities_source = 'default'` so the dashboard prompts for explicit confirmation;
 * the actual recommendation is computed separately by the
 * `business:recompute-legacy-recommendations` command once IndustryDefaultsService exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table): void {
            $table->string('recommended_primary_capability')->nullable()->after('business_type');
            $table->json('recommended_secondary_capabilities')->nullable()->after('recommended_primary_capability');
            $table->foreignId('recommended_source_draft_id')->nullable()->after('recommended_secondary_capabilities')->constrained('onboarding_drafts')->nullOnDelete();
            $table->unsignedInteger('recommended_source_revision')->nullable()->after('recommended_source_draft_id');
            $table->string('primary_capability')->nullable()->after('recommended_source_revision');
            $table->json('enabled_capabilities')->nullable()->after('primary_capability');
            $table->string('capabilities_source', 20)->default('default')->after('enabled_capabilities');
        });

        DB::table('salons')
            ->where(function ($query) {
                $query->where('mode', 'appointment')->orWhereNull('mode')->orWhere('mode', '');
            })
            ->update([
                'primary_capability' => 'appointment',
                'enabled_capabilities' => json_encode(['appointment']),
                'capabilities_source' => 'confirmed',
            ]);

        $legacy = DB::table('salons')->whereIn('mode', ['lead', 'reservation'])->get(['id', 'mode', 'business_type']);

        foreach ($legacy as $salon) {
            Log::warning('Legacy salon mode has no direct capability mapping, left unconfirmed for review', [
                'salon_id' => $salon->id,
                'legacy_mode' => $salon->mode,
                'business_type' => $salon->business_type,
            ]);
        }

        if ($legacy->isNotEmpty()) {
            DB::table('salons')
                ->whereIn('id', $legacy->pluck('id'))
                ->update([
                    'primary_capability' => null,
                    'enabled_capabilities' => json_encode([]),
                    'capabilities_source' => 'default',
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recommended_source_draft_id');
            $table->dropColumn([
                'recommended_primary_capability',
                'recommended_secondary_capabilities',
                'recommended_source_revision',
                'primary_capability',
                'enabled_capabilities',
                'capabilities_source',
            ]);
        });
    }
};
