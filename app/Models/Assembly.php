<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Assembly extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'description',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($assembly) {
            if (empty($assembly->id)) {
                $assembly->id = (string) Str::uuid();
            }
        });

        // When activating an assembly, deactivate all others
        static::updating(function ($assembly) {
            if ($assembly->isDirty('is_active') && $assembly->is_active) {
                static::where('id', '!=', $assembly->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }
        });
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AssemblyQuestion::class, 'assembly_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(AssemblyAttendance::class, 'assembly_id');
    }

    public function quorumConfigs(): HasMany
    {
        return $this->hasMany(QuorumConfig::class, 'assembly_id');
    }

    /**
     * Get the current active assembly
     */
    public static function getCurrent(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Get or create a default assembly for backward compatibility
     */
    public static function getOrCreateDefault(): self
    {
        $current = static::getCurrent();
        
        if (!$current) {
            $current = static::create([
                'name' => 'Asamblea Principal',
                'description' => 'Asamblea principal del sistema',
                'is_active' => true,
            ]);
        }

        return $current;
    }

    /**
     * Activate this assembly
     */
    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }
}
