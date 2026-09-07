<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConvenioPresidentSignBatch extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FINISHED = 'finished';

    public const SCOPE_IDS = 'ids';

    public const SCOPE_ALL = 'all';

    public const SCOPE_DATE_RANGE = 'date_range';

    protected $fillable = [
        'requested_by_user_id',
        'scope',
        'date_from',
        'date_to',
        'include_errors',
        'total',
        'signing',
        'ready_for_review',
        'errors',
        'completed',
        'status',
        'require_review',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'include_errors' => 'boolean',
            'total' => 'integer',
            'signing' => 'integer',
            'ready_for_review' => 'integer',
            'errors' => 'integer',
            'completed' => 'integer',
            'require_review' => 'boolean',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function trackings(): HasMany
    {
        return $this->hasMany(ConvenioEmailTracking::class, 'president_sign_batch_id');
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function refreshProgressCounts(): self
    {
        $counts = $this->trackings()
            ->selectRaw('signing_estado, COUNT(*) as aggregate')
            ->groupBy('signing_estado')
            ->pluck('aggregate', 'signing_estado');

        $signing = (int) ($counts[ConvenioEmailTracking::SIGNING_FIRMANDO_PRESIDENTE] ?? 0);
        $readyForReview = (int) ($counts[ConvenioEmailTracking::SIGNING_PENDIENTE_REVISION] ?? 0);
        $errors = (int) ($counts[ConvenioEmailTracking::SIGNING_ERROR_FIRMA_PRESIDENTE] ?? 0);
        $completed = (int) ($counts[ConvenioEmailTracking::SIGNING_COMPLETADO] ?? 0);

        $status = $signing > 0
            ? self::STATUS_PROCESSING
            : self::STATUS_FINISHED;

        $this->update([
            'signing' => $signing,
            'ready_for_review' => $readyForReview,
            'errors' => $errors,
            'completed' => $completed,
            'status' => $status,
        ]);

        return $this->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function toProgressPayload(): array
    {
        $this->refreshProgressCounts();

        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'date_from' => $this->date_from?->format('Y-m-d'),
            'date_to' => $this->date_to?->format('Y-m-d'),
            'include_errors' => $this->include_errors,
            'total' => $this->total,
            'signing' => $this->signing,
            'ready_for_review' => $this->ready_for_review,
            'errors' => $this->errors,
            'completed' => $this->completed,
            'status' => $this->status,
            'require_review' => $this->require_review,
            'processed' => $this->ready_for_review + $this->errors + $this->completed,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
