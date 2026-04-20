<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VotingSetting extends Model
{
    protected $fillable = [
        'active_mode',
        'active_candidate_election_key',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the current (singleton) voting setting, creating it if it doesn't exist.
     */
    public static function current(): self
    {
        return static::firstOrCreate([], ['active_mode' => 'none']);
    }

    public function isCandidateEnabled(): bool
    {
        return $this->active_mode === 'candidate';
    }

    public function isAssemblyEnabled(): bool
    {
        return $this->active_mode === 'assembly';
    }

    /**
     * Valid active_mode values.
     *
     * @return string[]
     */
    public static function validModes(): array
    {
        return ['none', 'candidate', 'assembly'];
    }
}
