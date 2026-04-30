<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $survey_id
 * @property string|null $respondent_document_type
 * @property string|null $respondent_document_number
 * @property string|null $respondent_name
 * @property string|null $hospital
 * @property string|null $signature_path
 * @property array|null $metadata
 * @property \Carbon\Carbon $submitted_at
 */
class SurveyResponse extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'survey_id',
        'respondent_document_type',
        'respondent_document_number',
        'respondent_name',
        'hospital',
        'signature_path',
        'metadata',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'submitted_at' => 'datetime',
        ];
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

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyResponseAnswer::class, 'response_id');
    }

    public function scopeByHospital(Builder $query, string $hospital): Builder
    {
        return $query->where('hospital', 'like', "%{$hospital}%");
    }

    public function scopeByDocument(Builder $query, string $document): Builder
    {
        return $query->where('respondent_document_number', 'like', "%{$document}%");
    }

    public function scopeByDateRange(Builder $query, ?string $start, ?string $end): Builder
    {
        if ($start) {
            $query->where('submitted_at', '>=', $start);
        }

        if ($end) {
            $query->where('submitted_at', '<=', $end.' 23:59:59');
        }

        return $query;
    }
}
