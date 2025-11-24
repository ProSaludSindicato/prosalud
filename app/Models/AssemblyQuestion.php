<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use App\Models\Assembly;

class AssemblyQuestion extends Model
{
    public const DEFAULT_OPTIONS = [
        ['id' => 'agree', 'text' => 'De acuerdo'],
        ['id' => 'disagree', 'text' => 'En desacuerdo'],
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'title',
        'description',
        'help_text',
        'type',
        'majority_type',
        'status',
        'time_limit',
        'quorum_required',
        'allow_change_vote',
        'order',
        'opened_at',
        'closed_at',
        'votes_count',
        'results_visible',
        'assembly_id',
    ];

    protected $casts = [
        'quorum_required' => 'boolean',
        'allow_change_vote' => 'boolean',
        'results_visible' => 'boolean',
        'time_limit' => 'integer',
        'order' => 'integer',
        'votes_count' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($question) {
            if (empty($question->id)) {
                $question->id = (string) Str::uuid();
            }
            // Auto-assign assembly_id from active assembly if not set
            if (empty($question->assembly_id)) {
                $assembly = Assembly::getCurrent();
                if ($assembly) {
                    $question->assembly_id = $assembly->id;
                } else {
                    throw new \RuntimeException(
                        'No se puede crear una pregunta sin asamblea activa. ' .
                        'Por favor, active una asamblea primero o especifique assembly_id.'
                    );
                }
            }
        });

        static::created(function (AssemblyQuestion $question) {
            $question->syncDefaultOptions();
        });
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class, 'question_id')->orderBy('order');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(AssemblyVote::class, 'question_id');
    }

    public function assembly(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Assembly::class, 'assembly_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'OPEN';
    }

    public function isClosed(): bool
    {
        return $this->status === 'CLOSED';
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function canVote(): bool
    {
        return $this->isOpen();
    }

    public function incrementVotesCount(): void
    {
        $this->increment('votes_count');
    }

    public function decrementVotesCount(): void
    {
        $this->decrement('votes_count');
    }

    public static function defaultOptionKeys(): array
    {
        return array_column(self::DEFAULT_OPTIONS, 'id');
    }

    public static function defaultOptions(): SupportCollection
    {
        return collect(self::DEFAULT_OPTIONS);
    }

    public function syncDefaultOptions(): void
    {
        $allowedKeys = self::defaultOptionKeys();

        foreach (self::DEFAULT_OPTIONS as $index => $option) {
            $this->options()->updateOrCreate(
                ['option_key' => $option['id']],
                [
                    'text' => $option['text'],
                    'description' => null,
                    'order' => $index,
                ]
            );
        }

        $this->options()->whereNotIn('option_key', $allowedKeys)->delete();

        $this->load('options');
    }
}
