<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delegate (proxy) attending the assembly on behalf of another delegate, as listed in the delegates file.
 */
class AssemblyProxy extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'assembly_id',
        'proxy_document',
        'proxy_name',
        'represented_document',
        'represented_name',
        'status',
        'declared_at',
        'revoked_at',
        'revoked_reason',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'declared_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function assembly(): BelongsTo
    {
        return $this->belongsTo(Assembly::class, 'assembly_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(AssemblyVote::class, 'proxy_id');
    }

    /**
     * @param  Builder<AssemblyProxy>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function revoke(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }
}
