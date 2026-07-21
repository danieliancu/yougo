<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_drafts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source_type', 40);
            $table->string('source_url', 2048)->nullable();
            $table->string('normalized_source_url', 2048)->nullable();
            $table->string('schema_version', 20)->default('1.0');

            $table->string('status', 30);
            // Deliberately NOT a database-generated column: MySQL/InnoDB refuses to let a
            // generated column derive from a base column (salon_id) that also carries an
            // ON DELETE CASCADE foreign key — "Cannot add foreign key constraint" (error
            // 1215) — because a cascading delete can't correctly recompute the generated
            // value. MariaDB doesn't enforce this, which is why this worked in local dev
            // (MariaDB 10.4) and only failed against production MySQL (8.4). Kept in sync
            // from PHP instead — see OnboardingDraft::booted().
            $table->unsignedBigInteger('active_slot')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedTinyInteger('attempt_count')->default(0);

            $table->uuid('claim_token')->nullable();
            $table->timestamp('processing_started_at')->nullable();

            $table->json('raw_extraction_result')->nullable();
            $table->string('raw_result_storage_path', 255)->nullable();
            $table->string('raw_result_checksum', 64)->nullable();
            $table->unsignedBigInteger('raw_result_size_bytes')->nullable();
            $table->string('raw_result_content_type', 100)->nullable();
            $table->timestamp('raw_result_purge_after')->nullable();
            $table->timestamp('raw_result_purged_at')->nullable();

            $table->json('normalized_extraction_result')->nullable();
            $table->json('validation_errors')->nullable();

            $table->string('failure_code', 60)->nullable();
            $table->string('failure_message', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->string('idempotency_key', 100)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['salon_id', 'status']);
            // No composite index on normalized_source_url: at varchar(2048) with utf8mb4
            // (4 bytes/char) it would exceed MySQL/InnoDB's 3072-byte max key length.
            // Nothing queries by it directly today — the active draft is looked up by
            // (salon_id, status) and compared to normalized_source_url in PHP; the
            // idempotency_key column (short, hashed) is what's actually indexed/queried
            // for exact-match lookups.
            $table->unique('active_slot', 'onboarding_drafts_active_slot_unique');
            $table->unique(['salon_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_drafts');
    }
};
