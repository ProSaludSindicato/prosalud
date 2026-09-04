<?php

namespace App\Models;

use App\Enums\ConvenioPdfStage;
use App\Enums\ConvenioTextIntegrityStatus;
use App\Services\ConvenioPdfStorageService;
use App\Support\ConvenioDisplayFilename;
use App\Support\ConvenioSigningAuditLog;
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
        'pdf_original_sha256',
        'pdf_original_page_count',
        'pdf_original_text_fingerprint',
        'pdf_firmado_afiliado_sha256',
        'text_integrity_status',
        'firmado_afiliado_at',
        'signed_ip',
        'signed_user_agent',
        'signing_audit_log',
        'terms_accepted_at',
        'signing_satisfaction_score',
        'signing_satisfaction_rated_at',
        'firmado_presidente_at',
        'rechazado_at',
        'motivo_rechazo',
        'sede',
        'generated_by_user_id',
        'convenio_data',
        'is_test',
    ];

    protected $hidden = [
        'signing_token_hash',
    ];

    protected $casts = [
        'convenio_data' => 'array',
        'signing_audit_log' => 'array',
        'text_integrity_status' => ConvenioTextIntegrityStatus::class,
        'is_test' => 'boolean',
        'enviado_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'intentos' => 'integer',
        'token_expires_at' => 'datetime',
        'firmado_afiliado_at' => 'datetime',
        'firmado_presidente_at' => 'datetime',
        'rechazado_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'signing_satisfaction_score' => 'integer',
        'signing_satisfaction_rated_at' => 'datetime',
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

    public function hasSigningSatisfactionRating(): bool
    {
        return $this->signing_satisfaction_score !== null;
    }

    public function canReceiveSigningSatisfactionRating(): bool
    {
        if ($this->hasSigningSatisfactionRating()) {
            return false;
        }

        return in_array($this->signing_estado, [
            self::SIGNING_FIRMADO_AFILIADO,
            self::SIGNING_COMPLETADO,
        ], true);
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
     * @param  \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>
     */
    public function scopeByCalificacion($query, string|int $calificacion)
    {
        if ($calificacion === 'sin_calificar') {
            return $query->whereNull('signing_satisfaction_score');
        }

        return $query->where('signing_satisfaction_score', (int) $calificacion);
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
            'error_message' => null,
        ]);
    }

    public function marcarComoFallido(string $errorMessage): void
    {
        $this->update([
            'estado' => 'fallido',
            'error_message' => $errorMessage,
            'intentos' => $this->intentos + 1,
        ]);
    }

    public function resolveVisibleErrorMessage(): ?string
    {
        if ($this->estado !== 'fallido') {
            return null;
        }

        return $this->error_message;
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

    public function resolveDownloadFilename(): string
    {
        return ConvenioDisplayFilename::fromTracking($this);
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
     * @return array{
     *     text_integrity_status: string|null,
     *     text_integrity_label: string|null,
     *     pdf_original_sha256: string|null,
     *     pdf_firmado_afiliado_sha256: string|null,
     *     firmado_afiliado_at: string|null,
     *     signed_ip: string|null,
     *     signed_user_agent: string|null,
     *     signing_audit_log: array<string, mixed>|null,
     *     terms_accepted_at: string|null
     * }
     */
    public function resolveIntegrityPayload(): array
    {
        $status = $this->text_integrity_status;

        return [
            'text_integrity_status' => $status?->value,
            'text_integrity_label' => match ($status) {
                ConvenioTextIntegrityStatus::Matched => 'Texto íntegro',
                ConvenioTextIntegrityStatus::Unavailable => 'Sin verificar texto',
                default => null,
            },
            'pdf_original_sha256' => $this->pdf_original_sha256,
            'pdf_firmado_afiliado_sha256' => $this->pdf_firmado_afiliado_sha256,
            'firmado_afiliado_at' => $this->firmado_afiliado_at?->toIso8601String(),
            'signed_ip' => $this->signed_ip,
            'signed_user_agent' => $this->signed_user_agent,
            'signing_audit_log' => ConvenioSigningAuditLog::present($this->signing_audit_log),
            'terms_accepted_at' => $this->terms_accepted_at?->toIso8601String(),
        ];
    }

    public function resolveIntegrityBadgeLabel(): ?string
    {
        if ($this->signing_estado !== self::SIGNING_FIRMADO_AFILIADO
            && $this->signing_estado !== self::SIGNING_COMPLETADO) {
            return null;
        }

        return match ($this->text_integrity_status) {
            ConvenioTextIntegrityStatus::Matched => 'Texto íntegro',
            ConvenioTextIntegrityStatus::Unavailable => 'Sin verificar texto',
            default => null,
        };
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
