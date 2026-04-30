<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $title
 * @property string|null $description
 * @property string $status draft|active|closed
 * @property string $access_type public|authenticated|restricted
 * @property list<string> $allowed_affiliate_statuses e.g. ['activo'], ['retirado'], ['activo','retirado']
 * @property bool $requires_signature
 * @property bool $allows_multiple_responses
 * @property \Carbon\Carbon|null $start_date
 * @property \Carbon\Carbon|null $end_date
 * @property int|null $created_by
 */
class Survey extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'title',
        'description',
        'status',
        'access_type',
        'allowed_affiliate_statuses',
        'requires_signature',
        'allows_multiple_responses',
        'start_date',
        'end_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allowed_affiliate_statuses' => 'array',
            'requires_signature' => 'boolean',
            'allows_multiple_responses' => 'boolean',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return static::where('id', (string) $value)->firstOrFail();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = $model->generateUnique10DigitId();
            }
        });
    }

    private function generateUnique10DigitId(): string
    {
        do {
            $firstDigit = mt_rand(1, 9);
            $timestamp = time();
            $random = mt_rand(1000, 9999);
            $idString = $firstDigit.substr($timestamp, -5).str_pad((string) $random, 4, '0', STR_PAD_LEFT);
        } while (static::where('id', $idString)->exists());

        return $idString;
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function hospitals(): BelongsToMany
    {
        return $this->belongsToMany(Hospital::class, 'survey_hospital');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeByAccessType(Builder $query, string $type): Builder
    {
        return $query->where('access_type', $type);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isPubliclyAccessible(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $now = now();

        if ($this->start_date && $now->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && $now->gt($this->end_date)) {
            return false;
        }

        return true;
    }

    public function allowsDocument(string $documentNumber): bool
    {
        if ($this->allows_multiple_responses) {
            return true;
        }

        return ! $this->responses()
            ->where('respondent_document_number', $documentNumber)
            ->exists();
    }
}
