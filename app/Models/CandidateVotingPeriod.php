<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateVotingPeriod extends Model
{
    /** @use HasFactory<\Database\Factories\CandidateVotingPeriodFactory> */
    use HasFactory;

    protected $fillable = [
        'election_key',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function current(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }
}
