<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class QuestionOption extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'question_id',
        'option_key',
        'text',
        'description',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($option) {
            if (empty($option->id)) {
                $option->id = (string) Str::uuid();
            }
        });
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(AssemblyQuestion::class, 'question_id');
    }
}
