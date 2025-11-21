<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AssemblyVote extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'question_id',
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
        });
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(AssemblyQuestion::class, 'question_id');
    }
}
