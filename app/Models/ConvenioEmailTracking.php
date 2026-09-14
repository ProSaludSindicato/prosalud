<?php

namespace App\Models;

use App\Enums\ConvenioPdfStage;
use App\Enums\ConvenioTextIntegrityStatus;
use App\Services\ConvenioPdfStorageService;
use App\Support\ConvenioDisplayFilename;
use App\Support\ConvenioSemesterPeriod;
use App\Support\ConvenioSigningAuditLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConvenioEmailTracking extends Model
{
    use HasFactory;

    public const SIGNING_PENDIENTE_FIRMA = 'pendiente_firma';

    public const SIGNING_FIRMADO_AFILIADO = 'firmado_afiliado';

    public const SIGNING_FIRMANDO_PRESIDENTE = 'firmando_presidente';

    public const SIGNING_ERROR_FIRMA_PRESIDENTE = 'error_firma_presidente';

    public const SIGNING_PENDIENTE_REVISION = 'pendiente_revision';

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
        'president_sign_last_error',
        'president_sign_batch_id',
        'president_sign_requested_by_user_id',
        'completed_by_user_id',
        'completed_at',
        'completed_email_sent_at',
        'completed_email_last_error',
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
        'completed_at' => 'datetime',
        'completed_email_sent_at' => 'datetime',
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
            self::SIGNING_FIRMANDO_PRESIDENTE,
            self::SIGNING_PENDIENTE_REVISION,
            self::SIGNING_ERROR_FIRMA_PRESIDENTE,
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
     * @param  \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>
     */
    public function scopeByPeriodo($query, string $periodo)
    {
        $range = ConvenioSemesterPeriod::dateRange($periodo);

        if ($range === null) {
            return $query;
        }

        return $query->whereBetween('created_at', [$range['start'], $range['end']]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>  $query
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>
     */
    public function scopeApplyHistoryFilters($query, array $filters, bool $digitalSigningEnabled = true)
    {
        $search = isset($filters['q']) ? trim((string) $filters['q']) : '';

        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $query->where(function ($builder) use ($pattern): void {
                $builder->where('documento', 'like', $pattern)
                    ->orWhere('nombre_convenio', 'like', $pattern);
            });
        } else {
            if (! empty($filters['documento'])) {
                $query->byDocumento((string) $filters['documento']);
            }

            if (! empty($filters['nombre_convenio'])) {
                $query->byNombreConvenio((string) $filters['nombre_convenio']);
            }
        }

        $estadoFiltro = $filters['estado_filtro'] ?? null;
        if (is_string($estadoFiltro) && $estadoFiltro !== '' && $estadoFiltro !== 'todos') {
            $isSigningFilter = in_array($estadoFiltro, [
                'firma_pendiente_firma',
                'firma_firmado_afiliado',
                'firma_completado',
                'firma_pendiente_revision',
                'firma_error_presidente',
                'firma_rechazado',
            ], true);

            if (! $isSigningFilter || $digitalSigningEnabled) {
                match ($estadoFiltro) {
                    'pendiente', 'enviado', 'fallido' => $query->byEstado($estadoFiltro),
                    'verificacion', 'test' => $query->test(),
                    'firma_pendiente_firma' => $query->bySigningEstado(self::SIGNING_PENDIENTE_FIRMA),
                    'firma_firmado_afiliado' => $query->bySigningEstado(self::SIGNING_FIRMADO_AFILIADO),
                    'firma_completado' => $query->bySigningEstado(self::SIGNING_COMPLETADO),
                    'firma_pendiente_revision' => $query->bySigningEstado(self::SIGNING_PENDIENTE_REVISION),
                    'firma_error_presidente' => $query->bySigningEstado(self::SIGNING_ERROR_FIRMA_PRESIDENTE),
                    'firma_rechazado' => $query->bySigningEstado(self::SIGNING_RECHAZADO),
                    default => null,
                };
            }
        } else {
            if (! empty($filters['estado'])) {
                $query->byEstado((string) $filters['estado']);
            }

            if ($digitalSigningEnabled && ! empty($filters['signing_estado'])) {
                $query->bySigningEstado((string) $filters['signing_estado']);
            }
        }

        if (! empty($filters['sede'])) {
            $sede = trim((string) $filters['sede']);
            $pattern = '%'.$sede.'%';
            $query->where(function ($builder) use ($pattern): void {
                $builder->where('sede', 'like', $pattern)
                    ->orWhere('nombre_convenio', 'like', $pattern);
            });
        }

        if (array_key_exists('is_test', $filters) && $filters['is_test'] !== null && $filters['is_test'] !== '') {
            $query->where('is_test', filter_var($filters['is_test'], FILTER_VALIDATE_BOOLEAN));
        }

        $periodo = $filters['periodo'] ?? null;
        if (is_string($periodo) && ConvenioSemesterPeriod::isValid($periodo)) {
            $query->byPeriodo($periodo);
        }

        $fechaDesde = $filters['fecha_desde'] ?? null;
        $fechaHasta = $filters['fecha_hasta'] ?? null;
        if ($fechaDesde || $fechaHasta) {
            $query->byFechaRango(
                $fechaDesde ?: '1970-01-01',
                $fechaHasta ?: now()->format('Y-m-d'),
            );
        }

        if (! empty($filters['calificacion'])) {
            $query->byCalificacion((string) $filters['calificacion']);
        }

        return $query;
    }

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

    public function affiliateHasSigned(): bool
    {
        if ($this->firmado_afiliado_at !== null) {
            return true;
        }

        return in_array($this->signing_estado, [
            self::SIGNING_FIRMADO_AFILIADO,
            self::SIGNING_FIRMANDO_PRESIDENTE,
            self::SIGNING_PENDIENTE_REVISION,
            self::SIGNING_ERROR_FIRMA_PRESIDENTE,
            self::SIGNING_COMPLETADO,
        ], true);
    }

    public function isInvalidated(): bool
    {
        return $this->signing_estado === self::SIGNING_RECHAZADO;
    }

    public function isEligibleForInvalidation(): bool
    {
        if ($this->isInvalidated()) {
            return false;
        }

        if ($this->signing_estado === self::SIGNING_COMPLETADO) {
            return false;
        }

        if ($this->signing_estado === null || $this->signing_estado === self::SIGNING_PENDIENTE_FIRMA) {
            return true;
        }

        return in_array($this->signing_estado, [
            self::SIGNING_FIRMADO_AFILIADO,
            self::SIGNING_FIRMANDO_PRESIDENTE,
            self::SIGNING_PENDIENTE_REVISION,
            self::SIGNING_ERROR_FIRMA_PRESIDENTE,
        ], true);
    }

    public function isEligibleForPresidentSign(): bool
    {
        return ! $this->isInvalidated()
            && in_array($this->signing_estado, self::presidentSignEligibleStates(), true)
            && filled($this->pdf_firmado_afiliado_path)
            && $this->firmado_presidente_at === null;
    }

    public function isEligibleForAffiliateResign(): bool
    {
        if ($this->isInvalidated()) {
            return false;
        }

        if (! $this->affiliateHasSigned()) {
            return false;
        }

        if (! $this->hasOriginalPathRecorded()) {
            return false;
        }

        return in_array($this->signing_estado, [
            self::SIGNING_FIRMADO_AFILIADO,
            self::SIGNING_ERROR_FIRMA_PRESIDENTE,
            self::SIGNING_PENDIENTE_REVISION,
        ], true);
    }

    public function isEligibleForReviewComplete(): bool
    {
        return ! $this->isInvalidated()
            && $this->signing_estado === self::SIGNING_PENDIENTE_REVISION
            && filled($this->pdf_final_path);
    }

    public function isEligibleForReviewError(): bool
    {
        return ! $this->isInvalidated()
            && $this->signing_estado === self::SIGNING_PENDIENTE_REVISION;
    }

    /**
     * @return list<string>
     */
    public static function presidentSignEligibleStates(): array
    {
        return [
            self::SIGNING_FIRMADO_AFILIADO,
            self::SIGNING_ERROR_FIRMA_PRESIDENTE,
        ];
    }

    public function hasOriginalPathRecorded(): bool
    {
        if (filled($this->pdf_original_path) || filled($this->ruta_archivo_pdf)) {
            return true;
        }

        return filled($this->parentTracking?->pdf_original_path);
    }

    public function hasSignedPathRecorded(): bool
    {
        return filled($this->pdf_firmado_afiliado_path) || filled($this->pdf_final_path);
    }

    public function hasAffiliateSignedPdfAvailable(bool $verifyStorage = false): bool
    {
        $storage = app(ConvenioPdfStorageService::class);

        if ($verifyStorage) {
            if ($storage->hasStage($this, ConvenioPdfStage::FirmadoAfiliado)) {
                return true;
            }

            if (
                $this->signing_estado === self::SIGNING_ERROR_FIRMA_PRESIDENTE
                && filled($this->firmado_afiliado_at)
            ) {
                return $storage->hasStage($this, ConvenioPdfStage::Final);
            }

            return false;
        }

        if (filled($this->pdf_firmado_afiliado_path)) {
            return true;
        }

        if (
            $this->signing_estado === self::SIGNING_ERROR_FIRMA_PRESIDENTE
            && filled($this->firmado_afiliado_at)
            && filled($this->pdf_final_path)
        ) {
            return true;
        }

        return filled($this->firmado_afiliado_at)
            && in_array($this->signing_estado, [
                self::SIGNING_FIRMADO_AFILIADO,
                self::SIGNING_FIRMANDO_PRESIDENTE,
                self::SIGNING_ERROR_FIRMA_PRESIDENTE,
            ], true);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ConvenioEmailTracking>
     */
    public function scopeForHistoryList($query)
    {
        return $query->select([
            'id',
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
            'signing_estado',
            'pdf_original_path',
            'pdf_firmado_afiliado_path',
            'pdf_final_path',
            'text_integrity_status',
            'firmado_afiliado_at',
            'firmado_presidente_at',
            'rechazado_at',
            'motivo_rechazo',
            'sede',
            'is_test',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * @return array{resend: bool, download_original: bool, download_final: bool, president_sign: bool, complete_review: bool, mark_review_error: bool, mark_invalid: bool, preview_pdf: bool, request_affiliate_resign: bool}
     */
    public function resolveAvailableActions(bool $digitalSigningEnabled, bool $verifyStorage = false): array
    {
        $storage = app(ConvenioPdfStorageService::class);
        $hasOriginal = $verifyStorage
            ? $storage->hasOriginal($this)
            : $this->hasOriginalPathRecorded();

        $canResend = in_array($this->estado, ['enviado', 'fallido', self::ESTADO_VERIFICACION], true);

        if ($this->isInvalidated() || ($digitalSigningEnabled && $this->affiliateHasSigned())) {
            $canResend = false;
        }

        $canDownloadFinal = false;
        if ($digitalSigningEnabled) {
            $canDownloadFinal = match (true) {
                in_array($this->signing_estado, [
                    self::SIGNING_FIRMADO_AFILIADO,
                    self::SIGNING_FIRMANDO_PRESIDENTE,
                    self::SIGNING_ERROR_FIRMA_PRESIDENTE,
                ], true) => $this->hasAffiliateSignedPdfAvailable($verifyStorage),
                $this->signing_estado === self::SIGNING_COMPLETADO => $verifyStorage
                    ? (
                        $storage->hasStage($this, ConvenioPdfStage::Final)
                        || $storage->hasStage($this, ConvenioPdfStage::FirmadoAfiliado)
                    )
                    : $this->hasSignedPathRecorded(),
                default => false,
            };
        }

        $canRequestAffiliateResign = $digitalSigningEnabled && $this->isEligibleForAffiliateResign();
        if ($verifyStorage && $canRequestAffiliateResign) {
            $canRequestAffiliateResign = $storage->hasOriginal($this);
        }

        return [
            'resend' => $canResend && $hasOriginal,
            'download_original' => $hasOriginal,
            'download_final' => $canDownloadFinal,
            'president_sign' => $digitalSigningEnabled && $this->isEligibleForPresidentSign(),
            'complete_review' => $digitalSigningEnabled && $this->isEligibleForReviewComplete(),
            'mark_review_error' => $digitalSigningEnabled && $this->isEligibleForReviewError(),
            'mark_invalid' => $this->isEligibleForInvalidation(),
            'preview_pdf' => $digitalSigningEnabled && (
                $this->isEligibleForReviewComplete()
                || in_array($this->signing_estado, [
                    self::SIGNING_FIRMADO_AFILIADO,
                    self::SIGNING_COMPLETADO,
                ], true)
            ),
            'request_affiliate_resign' => $canRequestAffiliateResign,
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
        if (! in_array($this->signing_estado, [
            self::SIGNING_FIRMADO_AFILIADO,
            self::SIGNING_FIRMANDO_PRESIDENTE,
            self::SIGNING_PENDIENTE_REVISION,
            self::SIGNING_ERROR_FIRMA_PRESIDENTE,
            self::SIGNING_COMPLETADO,
        ], true)) {
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

    public function presidentSignBatch()
    {
        return $this->belongsTo(ConvenioPresidentSignBatch::class, 'president_sign_batch_id');
    }

    public function presidentSignRequestedBy()
    {
        return $this->belongsTo(User::class, 'president_sign_requested_by_user_id');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
