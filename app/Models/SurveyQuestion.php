<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $survey_id
 * @property string $type text|textarea|single_choice|multiple_choice|date|number|scale|ranking|yes_no
 * @property string $label
 * @property string|null $help_text
 * @property bool $is_required
 * @property int $order
 * @property array|null $options
 * @property bool $ranking_unique_priority
 */
class SurveyQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'survey_id',
        'type',
        'label',
        'help_text',
        'is_required',
        'order',
        'options',
        'ranking_unique_priority',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'order' => 'integer',
            'options' => 'array',
            'ranking_unique_priority' => 'boolean',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyResponseAnswer::class, 'question_id');
    }

    public function hasOptions(): bool
    {
        return in_array($this->type, ['single_choice', 'multiple_choice', 'ranking'], true);
    }
}
