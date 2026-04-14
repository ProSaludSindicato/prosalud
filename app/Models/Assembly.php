<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assembly extends Model
{
    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date',
        'is_active',
        'allows_reactivation',
        'delegates_file_path',
        'delegates_file_disk',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'allows_reactivation' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

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

    public function delegateFileVersions(): HasMany
    {
        return $this->hasMany(AssemblyDelegateFileVersion::class, 'assembly_id')->orderByDesc('created_at');
    }

    /**
     * Get the current active assembly
     */
    public static function getCurrent(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Get or create a default assembly for backward compatibility.
     *
     * WARNING: This method creates a new assembly automatically if none exists.
     * Use only when explicitly needed for backward compatibility.
     * Most code should use getCurrent() and handle the null case explicitly.
     *
     * @deprecated Prefer using getCurrent() and handling null cases explicitly
     */
    public static function getOrCreateDefault(): self
    {
        $current = static::getCurrent();

        if (! $current) {
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
