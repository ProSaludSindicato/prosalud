<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Models\Assembly;

class AssemblyVote extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'question_id',
        'assembly_id',
        'voter_id',
        'voter_name',
        'selected_options',
        'voted_at',
    ];

    protected $casts = [
        'selected_options' => 'array',
        'voted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($vote) {
            if (empty($vote->id)) {
                $vote->id = 'vote-' . Str::random(10);
            }
            if (empty($vote->voted_at)) {
                $vote->voted_at = now();
            }
            // Auto-assign assembly_id from question if not set
            if (empty($vote->assembly_id) && $vote->question_id) {
                $question = AssemblyQuestion::find($vote->question_id);
                if ($question && $question->assembly_id) {
                    $vote->assembly_id = $question->assembly_id;
                } else {
                    // Fallback: try to get from active assembly
                    $assembly = Assembly::getCurrent();
                    if ($assembly) {
                        $vote->assembly_id = $assembly->id;
                    } else {
                        throw new \RuntimeException(
                            'No se puede crear un voto sin asamblea. ' .
                            'La pregunta debe pertenecer a una asamblea o debe haber una asamblea activa.'
                        );
                    }
                }
            }
        });
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(AssemblyQuestion::class, 'question_id');
    }

    public function assembly(): BelongsTo
    {
        return $this->belongsTo(Assembly::class, 'assembly_id');
    }
}
