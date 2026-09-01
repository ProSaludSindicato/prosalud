<?php

namespace App\Models;

use App\Enums\ConvenioPdfStage;
use App\Services\ConvenioPdfStorageService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConvenioEmailTracking extends Model
{
    use HasFactory;

    public const SIGNING_PENDIENTE_FIRMA = 'pendiente_firma';

    public const SIGNING_FIRMADO_AFILIADO = 'firmado_afiliado';

    public const SIGNING_COMPLETADO = 'completado';

    public const SIGNING_RECHAZADO = 'rechazado';

    public const ESTADO_VERIFICACION = 'verificacion';

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
        'generated_by_user_id',
        'convenio_data',
        'is_test',
    ];

    protected $casts = [
        'convenio_data' => 'array',
        'is_test' => 'boolean',
        'enviado_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'intentos' => 'integer',
        'token_expires_at' => 'datetime',
        'firmado_afiliado_at' => 'datetime',
        'firmado_presidente_at' => 'datetime',
        'rechazado_at' => 'datetime',
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
    public function scopeReal($query)
    {
        return $query->where('is_test', false);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>
     */
    public function scopeTest($query)
    {
        return $query->where('is_test', true);
    }

    public function isTestRecord(): bool
    {
        return $this->is_test || $this->estado === self::ESTADO_VERIFICACION;
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
     * @param  \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>
     */
    public function scopeBySede($query, string $sede)
    {
        return $query->where('sede', $sede);
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

    public function marcarComoDisponibleVerificacion(): void
    {
        $this->update([
            'estado' => self::ESTADO_VERIFICACION,
        ]);
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
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
     * @return array{resend: bool, download_original: bool, download_final: bool}
     */
    public function resolveAvailableActions(bool $digitalSigningEnabled): array
    {
        $storage = app(ConvenioPdfStorageService::class);
        $hasOriginal = $storage->hasOriginal($this);

        $canResend = in_array($this->estado, ['enviado', 'fallido', self::ESTADO_VERIFICACION], true);

        $canDownloadFinal = false;
        if ($digitalSigningEnabled) {
            $canDownloadFinal = in_array($this->signing_estado, [
                self::SIGNING_FIRMADO_AFILIADO,
                self::SIGNING_COMPLETADO,
            ], true) && (
                $storage->hasStage($this, ConvenioPdfStage::FirmadoAfiliado)
                || $storage->hasStage($this, ConvenioPdfStage::Final)
            );
        }

        return [
            'resend' => $canResend && $hasOriginal,
            'download_original' => $hasOriginal,
            'download_final' => $canDownloadFinal,
        ];
    }

    public function resolveOriginalPdfAbsolutePath(): ?string
    {
        return app(ConvenioPdfStorageService::class)->materializeOriginalToTemp($this);
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
