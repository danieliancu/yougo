<?php

namespace App\Models;

use App\Enums\OnboardingDraftStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingDraft extends Model
{
    protected $fillable = [
        'salon_id',
        'created_by_user_id',
        'confirmed_by_user_id',
        'source_type',
        'source_url',
        'normalized_source_url',
        'schema_version',
        'status',
        'revision',
        'attempt_count',
        'claim_token',
        'processing_started_at',
        'raw_extraction_result',
        'raw_result_storage_path',
        'raw_result_checksum',
        'raw_result_size_bytes',
        'raw_result_content_type',
        'raw_result_purge_after',
        'raw_result_purged_at',
        'normalized_extraction_result',
        'validation_errors',
        'failure_code',
        'failure_message',
        'started_at',
        'finished_at',
        'confirmed_at',
        'superseded_at',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnboardingDraftStatus::class,
            'revision' => 'integer',
            'attempt_count' => 'integer',
            'raw_result_size_bytes' => 'integer',
            'processing_started_at' => 'datetime',
            'raw_extraction_result' => 'array',
            'raw_result_purge_after' => 'datetime',
            'raw_result_purged_at' => 'datetime',
            'normalized_extraction_result' => 'array',
            'validation_errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', OnboardingDraftStatus::activeValues());
    }

    /**
     * Whether this draft's current processing claim is still "in flight" (i.e. not old
     * enough to be treated as a crashed job that can be safely reclaimed).
     */
    public function isClaimFresh(): bool
    {
        if (! $this->processing_started_at) {
            return false;
        }

        $staleAfter = (int) config('onboarding.job.stale_after_seconds', 120);

        return $this->processing_started_at->greaterThan(now()->subSeconds($staleAfter));
    }
}
