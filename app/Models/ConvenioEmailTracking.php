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
     */
    public function scopeByFechaRango($query, $fechaInicio, $fechaFin)
    {
        return $query->whereBetween('enviado_at', [$fechaInicio, $fechaFin]);
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
     * Incrementar intentos
     */
    public function incrementarIntentos(): void
    {
        $this->increment('intentos');
    }
}

