<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConvenioEmailTracking extends Model
{
    use HasFactory;

    public const SIGNING_PENDIENTE_FIRMA = 'pendiente_firma';

    public const SIGNING_FIRMADO_AFILIADO = 'firmado_afiliado';

    public const SIGNING_FIRMANDO_PRESIDENTE = 'firmando_presidente';

    public const SIGNING_ERROR_PRESIDENTE = 'error_firma_presidente';

    public const SIGNING_COMPLETADO = 'completado';

    public const SIGNING_RECHAZADO = 'rechazado';

    protected $table = 'convenio_email_tracking';

    protected $fillable = [
        'documento',
        'nombre_afiliado',
        'email_afiliado',
        'nombre_convenio',
        'viewer_header_title',
        'nombre_archivo',
        'ruta_archivo_pdf',
        'enviado_at',
        'estado',
        'error_message',
        'intentos',
        'parent_tracking_id',
        'signing_token_hash',
        'token_expires_at',
        'signing_estado',
        'pdf_original_path',
        'pdf_firmado_afiliado_path',
        'pdf_final_path',
        'firmado_afiliado_at',
        'firmado_presidente_at',
        'rechazado_at',
        'motivo_rechazo',
        'sede',
        'president_sign_attempts',
        'president_sign_last_error',
        'president_sign_detection_method',
        'president_sign_queued_at',
        'president_sign_duration_ms',
    ];

    protected $casts = [
        'enviado_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'intentos' => 'integer',
        'token_expires_at' => 'datetime',
        'firmado_afiliado_at' => 'datetime',
        'firmado_presidente_at' => 'datetime',
        'rechazado_at' => 'datetime',
        'president_sign_queued_at' => 'datetime',
        'president_sign_attempts' => 'integer',
        'president_sign_duration_ms' => 'integer',
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
     * @param  \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>
     */
    public function scopeBySigningEstado($query, string $signingEstado)
    {
        return $query->where('signing_estado', $signingEstado);
    }

    /**
     * Filtra por coincidencia parcial en sede o en nombre del convenio.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>
     */
    public function scopeBySede($query, string $sede)
    {
        $term = trim($sede);
        if ($term === '') {
            return $query;
        }

        $pattern = '%'.$term.'%';

        return $query->where(function ($q) use ($pattern): void {
            $q->where('sede', 'like', $pattern)
                ->orWhere('nombre_convenio', 'like', $pattern);
        });
    }

    /**
     * Scope para buscar por rango de fechas
     * Usa created_at para incluir registros pendientes que aún no tienen enviado_at
     */
    public function scopeByFechaRango($query, $fechaInicio, $fechaFin)
    {
        if ($fechaInicio) {
            try {
                $startDate = Carbon::parse($fechaInicio)->startOfDay();
                $query->where('created_at', '>=', $startDate);
            } catch (\Exception $e) {
                // Ignorar fecha inválida
            }
        }

        if ($fechaFin) {
            try {
                $endDate = Carbon::parse($fechaFin)->endOfDay();
                $query->where('created_at', '<=', $endDate);
            } catch (\Exception $e) {
                // Ignorar fecha inválida
            }
        }

        return $query;
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
        if (! $this->parent_tracking_id) {
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

    /**
     * Scope para convenios pendientes de firma del presidente.
     * Incluye los que fallaron (reintentables) y los que esperan ser firmados.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>
     */
    public function scopePendingPresidentSign($query)
    {
        return $query->whereIn('signing_estado', [
            self::SIGNING_FIRMADO_AFILIADO,
            self::SIGNING_ERROR_PRESIDENTE,
        ]);
    }
}
