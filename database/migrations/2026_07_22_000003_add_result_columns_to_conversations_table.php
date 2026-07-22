<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `result_type`/`result_id` are the single canonical source of truth for "what did this
 * conversation produce" (Task 4 §8) — a morphTo pair enforced via Relation::enforceMorphMap
 * (AppServiceProvider::boot()). `booking_id` stays on the table as a read-only compatibility
 * mirror for existing code paths that still read `conversation->booking_id`/`->booking`
 * directly; nothing should ever branch on it to decide whether a conversation already has
 * a result — that check always goes through result_type/result_id under a row lock (see
 * ConversationResultService).
 *
 * The unique index on (result_type, result_id) is a plain index, not a MySQL generated/
 * stored column — the onboarding_drafts migration's MySQL/cascade-FK generated-column
 * failure doesn't apply here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('result_type')->nullable()->after('booking_id');
            $table->unsignedBigInteger('result_id')->nullable()->after('result_type');
            $table->unique(['result_type', 'result_id']);
        });

        DB::table('conversations')
            ->whereNotNull('booking_id')
            ->update([
                'result_type' => 'booking',
                'result_id' => DB::raw('booking_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique(['result_type', 'result_id']);
            $table->dropColumn(['result_type', 'result_id']);
        });
    }
};
