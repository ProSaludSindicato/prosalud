<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Assembly;

class QuorumConfig extends Model
{
    public $timestamps = false;
    const UPDATED_AT = 'updated_at';

    protected $table = 'quorum_config';

    protected $fillable = [
        'total_delegates',
        'present_delegates',
        'required_percentage',
        'verified',
        'assembly_id',
    ];

    protected $casts = [
        'total_delegates' => 'integer',
        'present_delegates' => 'integer',
        'required_percentage' => 'integer',
        'verified' => 'boolean',
        'updated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($quorum) {
            // Auto-assign assembly_id from active assembly if not set
            if (empty($quorum->assembly_id)) {
                $assembly = Assembly::getCurrent();
                if (!$assembly) {
                    throw new \RuntimeException(
                        'No se puede crear una configuración de quórum sin asamblea activa. ' .
                        'Por favor, active una asamblea primero o especifique assembly_id.'
                    );
                }
                $quorum->assembly_id = $assembly->id;
            }
        });

        static::updating(function ($quorum) {
            // Auto-assign assembly_id from active assembly if not set (por si acaso)
            if (empty($quorum->assembly_id)) {
                $assembly = Assembly::getCurrent();
                if (!$assembly) {
                    throw new \RuntimeException(
                        'No se puede actualizar una configuración de quórum sin asamblea activa. ' .
                        'Por favor, active una asamblea primero o especifique assembly_id.'
                    );
                }
                $quorum->assembly_id = $assembly->id;
            }
        });
    }

    public function isQuorumMet(): bool
    {
        return $this->present_delegates >= ($this->total_delegates * $this->required_percentage / 100);
    }

    public function verify(): void
    {
        $this->verified = $this->isQuorumMet();
        $this->save();
    }

    public static function getCurrent(): ?self
    {
        $assembly = \App\Models\Assembly::getCurrent();
        if ($assembly) {
            return self::where('assembly_id', $assembly->id)
                ->orderBy('updated_at', 'desc')
                ->first();
        }
        return self::orderBy('updated_at', 'desc')->first();
    }

    public function assembly(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Assembly::class, 'assembly_id');
    }
}
