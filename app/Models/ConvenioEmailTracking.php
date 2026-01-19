<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ConvenioEmailTracking extends Model
{
    use HasFactory;

    protected $table = 'convenio_email_tracking';

    protected $fillable = [
        'documento',
        'nombre_afiliado',
        'email_afiliado',
        'nombre_convenio',
        'nombre_archivo',
        'ruta_archivo_pdf',
        'enviado_at',
        'estado',
        'error_message',
        'intentos',
        'parent_tracking_id',
    ];

    protected $casts = [
        'enviado_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'intentos' => 'integer',
    ];

    /**
     * Scope para buscar por documento
     */
    public function scopeByDocumento($query, string $documento)
    {
        return $query->where('documento', $documento);
    }

    /**
     * Scope para buscar por nombre de convenio
     */
    public function scopeByNombreConvenio($query, string $nombreConvenio)
    {
        return $query->where('nombre_convenio', $nombreConvenio);
    }

    /**
     * Scope para buscar por estado
     */
    public function scopeByEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    /**
     * Scope para buscar por rango de fechas
     * Usa created_at para incluir registros pendientes que aún no tienen enviado_at
     */
    public function scopeByFechaRango($query, $fechaInicio, $fechaFin)
    {
        return $query->whereBetween('created_at', [$fechaInicio, $fechaFin]);
    }

    /**
     * Marcar como enviado exitosamente
     */
    public function marcarComoEnviado(): void
    {
        $this->update([
            'estado' => 'enviado',
            'enviado_at' => now(),
        ]);
    }

    /**
     * Marcar como fallido
     */
    public function marcarComoFallido(string $errorMessage): void
    {
        $this->update([
            'estado' => 'fallido',
            'error_message' => $errorMessage,
            'intentos' => $this->intentos + 1,
        ]);
    }

    /**
     * Incrementar intentos en este registro y en todos los registros relacionados
     */
    public function incrementarIntentos(): void
    {
        $nuevoNumeroIntentos = $this->intentos + 1;
        
        // Actualizar este registro
        $this->update(['intentos' => $nuevoNumeroIntentos]);
        
        // Si este es un registro original (no tiene parent), actualizar todos sus reenvíos
        if (!$this->parent_tracking_id) {
            $this->resends()->update(['intentos' => $nuevoNumeroIntentos]);
        } else {
            // Si este es un reenvío, actualizar el padre y todos los hermanos (otros reenvíos del mismo padre)
            $parentTracking = $this->parentTracking;
            if ($parentTracking) {
                $parentTracking->update(['intentos' => $nuevoNumeroIntentos]);
                $parentTracking->resends()->update(['intentos' => $nuevoNumeroIntentos]);
            }
        }
    }

    /**
     * Relación con el tracking padre (si es reenvío)
     */
    public function parentTracking()
    {
        return $this->belongsTo(ConvenioEmailTracking::class, 'parent_tracking_id');
    }

    /**
     * Relación con los reenvíos de este tracking
     */
    public function resends()
    {
        return $this->hasMany(ConvenioEmailTracking::class, 'parent_tracking_id');
    }
}

