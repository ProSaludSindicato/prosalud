<?php

namespace App\Models;

use App\Constants\RequestStatuses;
use App\Constants\RequestTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $request_type
 * @property string $document_type
 * @property string $document_number
 * @property string $name
 * @property string $last_name
 * @property string $email
 * @property string $phone_number
 * @property array|null $payload
 * @property array|null $files
 * @property string $status
 * @property string $created_at
 * @property string|null $processed_at
 * @property string $full_name
 * @property string $formatted_created_at
 * @property string $formatted_processed_at
 */
class RequestForm extends Model
{
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = [
        'id',
        'request_type',
        'document_type',
        'document_number',
        'name',
        'last_name',
        'email',
        'phone_number',
        'payload',
        'files',
        'status',
        'rejection_reason',
        'processed_at',
        'validated_at',
        'validated_by',
    ];

    protected $casts = [
        'id' => 'string',
        'payload' => 'array',
        'files' => 'array',
        'created_at' => 'datetime',
        'processed_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    protected $keyType = 'string';

    /**
     * Retrieve the model for route model binding.
     * This ensures IDs with leading zeros are handled correctly.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::where('id', (string) $value)->firstOrFail();
    }

    /**
     * Get the full name attribute.
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->name.' '.$this->last_name);
    }

    /**
     * Get formatted created_at attribute.
     */
    public function getFormattedCreatedAtAttribute(): string
    {
        if (! $this->created_at) {
            return '';
        }

        if (is_string($this->created_at)) {
            return date('d/m/Y H:i:s', strtotime($this->created_at));
        }

        return $this->created_at->format('d/m/Y H:i:s');
    }

    /**
     * Get formatted processed_at attribute.
     */
    public function getFormattedProcessedAtAttribute(): string
    {
        if (! $this->processed_at) {
            return '';
        }

        if (is_string($this->processed_at)) {
            return date('d/m/Y H:i:s', strtotime($this->processed_at));
        }

        return $this->processed_at->format('d/m/Y H:i:s');
    }

    /**
     * Scope for pending requests.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for processed requests.
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', 'processed');
    }

    /**
     * Scope for requests by type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('request_type', $type);
    }

    /**
     * Scope for requests by document number.
     */
    public function scopeByDocument(Builder $query, string $documentNumber): Builder
    {
        return $query->where('document_number', $documentNumber);
    }

    /**
     * Scope for requests by email.
     */
    public function scopeByEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', $email);
    }

    /**
     * Mark request as processed.
     */
    public function markAsProcessed(): bool
    {
        return $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    /**
     * Check if request is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if request is processed.
     */
    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    /**
     * Get payload value by key.
     */
    public function getPayloadValue(string $key, $default = null)
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * Status change history for this request.
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(RequestStatusLog::class, 'request_form_id', 'id')
            ->orderBy('created_at', 'asc');
    }

    /**
     * Get the request subtype (for types that support subtypes, e.g. verificacion-pagos).
     * This is primarily backed by payload['solicitudRelacionadaCon'].
     */
    public function getRequestSubtypeAttribute(): ?string
    {
        // Solo ciertos tipos de solicitud manejan subtipos
        if (! RequestTypes::hasSubtypes($this->request_type)) {
            return null;
        }

        $payload = $this->payload ?? [];

        return $payload['solicitudRelacionadaCon'] ?? null;
    }

    /**
     * Get the translated status in Spanish.
     */
    public function getTranslatedStatusAttribute(): string
    {
        return match (strtoupper($this->status)) {
            RequestStatuses::PENDING => 'Pendiente',
            RequestStatuses::IN_REVIEW => 'En revisión',
            RequestStatuses::REJECTED => 'Rechazada',
            RequestStatuses::COMPLETED => 'Completada',
            'PENDING' => 'Pendiente',
            'IN_REVIEW' => 'En revisión',
            'REJECTED' => 'Rechazada',
            'COMPLETED' => 'Completada',
            'pending' => 'Pendiente',
            'processed' => 'Procesada',
            default => ucfirst(strtolower($this->status)),
        };
    }

    /**
     * Get the translated request type in Spanish.
     */
    public function getTranslatedRequestTypeAttribute(): string
    {
        $requestTypeLabels = [
            RequestTypes::CERTIFICADO_CONVENIO => 'Certificado de Convenio',
            RequestTypes::COMPENSACION_ANUAL => 'Compensación Anual Diferida',
            RequestTypes::COMPENSACION_DESCANSO => 'Compensación por Descanso',
            RequestTypes::VERIFICACION_PAGOS => 'Verificación de Pagos',
            RequestTypes::SOLICITUD_RETIRO_SINDICAL => 'Retiro Sindical',
            RequestTypes::ACTUALIZAR_DATOS_PERSONALES => 'Actualizar Datos Personales',
            RequestTypes::SOLICITUD_MICROCREDITO => 'Microcrédito CEII',
            RequestTypes::INCAPACIDADES_LICENCIAS => 'Incapacidades y Licencias',
            // Alias para compatibilidad con datos antiguos
            'retiro-sindical' => 'Retiro Sindical',
            'incapacidad-licencia' => 'Incapacidades y Licencias',
            'incapacidad-laboral' => 'Incapacidades y Licencias',
            'solicitud-microcredito' => 'Microcrédito CEII', // Alias normalizado a 'microcredito'
            // Tipos adicionales mencionados en la documentación
            'permisos-turnos' => 'Permisos y Cambio de Turnos',
            'solicitud-bienestar' => 'Solicitud de Bienestar',
        ];

        return $requestTypeLabels[$this->request_type] ?? ucfirst(str_replace('-', ' ', $this->request_type));
    }

    /**
     * Get the translated document type in Spanish.
     */
    public function getTranslatedDocumentTypeAttribute(): string
    {
        $documentTypeLabels = [
            'CC' => 'Cédula de Ciudadanía',
            'CE' => 'Cédula de Extranjería',
            'TI' => 'Tarjeta de Identidad',
            'PA' => 'Pasaporte',
            'PT' => 'Permiso por Protección Temporal',
            'RC' => 'Registro Civil',
            'NUIP' => 'Número Único de Identificación Personal',
        ];

        $docType = strtoupper(trim($this->document_type ?? ''));

        return $documentTypeLabels[$docType] ?? $this->document_type;
    }

    /**
     * Format payload value for display in email.
     */
    public function formatPayloadValue(string $key, $value): string
    {
        // Special handling for montoSolicitado - format as COP currency
        if ($key === 'montoSolicitado' && is_numeric($value)) {
            return $this->formatCurrencyCOP($value);
        }

        // Special handling for certificado info
        if ($key === 'infoCertificado') {
            // Parse JSON string if needed
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }

            if (is_array($value)) {
                return $this->formatCertificadoInfo($value);
            }
        }

        // Special handling for beneficiarios nuevos
        if ($key === 'beneficiariosNuevos' && is_array($value)) {
            return $this->formatBeneficiariosNuevos($value);
        }

        // Special handling for beneficiarios eliminados
        if ($key === 'beneficiariosEliminados' && is_array($value)) {
            return $this->formatBeneficiariosEliminados($value);
        }

        // Format enum values for update data fields
        $enumFormatters = [
            'estadoCivil' => fn ($v) => $this->formatEstadoCivil($v),
            'tipoCuenta' => fn ($v) => $this->formatTipoCuenta($v),
            'banco' => fn ($v) => $this->formatBanco($v),
            'eps' => fn ($v) => $this->formatEps($v),
            'afp' => fn ($v) => $this->formatAfp($v),
            'nivelEducativo' => fn ($v) => $this->formatNivelEducativo($v),
            'tallaUniforme' => fn ($v) => strtoupper($v),
            'tallaCalzado' => fn ($v) => $v,
        ];

        if (isset($enumFormatters[$key])) {
            return $enumFormatters[$key]($value);
        }

        // Handle other arrays or objects
        if (is_array($value) || is_object($value)) {
            return $this->formatArrayValue($value);
        }

        return (string) $value;
    }

    /**
     * Parse various value types to boolean.
     */
    public function parseBooleanValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $lowerValue = strtolower(trim($value));

            return in_array($lowerValue, ['true', '1', 'yes', 'si', 'sí', 'on']);
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_array($value)) {
            return ! empty($value);
        }

        return false;
    }

    /**
     * Format field names in a user-friendly way.
     */
    public function formatFieldName(string $field): string
    {
        // Handle special cases first
        $specialCases = [
            'dirigidoAQuien' => 'Dirigido a quién',
            'fechaIngresoRetiro' => 'Fecha de ingreso/retiro',
            'valorCompensaciones' => 'Valor de compensaciones',
            'dirigidoAEntidad' => 'Dirigido a entidad',
            'paraSubsidioDesempleo' => 'Para subsidio de desempleo',
            'paraSubsidioVivienda' => 'Para subsidio de vivienda',
            'dirigidoFondoPensiones' => 'Dirigido a fondo de pensiones',
            'adicionarActividades' => 'Adicionar actividades',
            'dirigidoTransitoPicoPlaca' => 'Dirigido a tránsito pico y placa',
            'dirigidoBancolombia' => 'Dirigido a Bancolombia',
            // Update data fields and certificado-convenio fields
            'proceso' => 'Proceso',
            'dondeRealizaProceso' => 'Donde realiza el proceso',
            'estadoCivil' => 'Estado civil',
            'direccion' => 'Dirección',
            'municipio' => 'Municipio',
            'telefonoFijo' => 'Teléfono fijo',
            'celular' => 'Celular',
            'correo' => 'Correo electrónico',
            'nombreContactoEmergencia' => 'Nombre contacto de emergencia',
            'relacionContactoEmergencia' => 'Relación contacto de emergencia',
            'telefonoContactoEmergencia' => 'Teléfono contacto de emergencia',
            'tallaUniforme' => 'Talla de uniforme',
            'tallaCalzado' => 'Talla de calzado',
            'nivelEducativo' => 'Nivel educativo',
            'numeroCuenta' => 'Número de cuenta',
            'tipoCuenta' => 'Tipo de cuenta',
            'banco' => 'Banco',
            'eps' => 'EPS',
            'afp' => 'AFP',
            'beneficiariosNuevos' => 'Beneficiarios nuevos',
            'montoSolicitado' => 'Monto Solicitado',
            'numeroCuotas' => 'Número de Cuotas',
        ];

        if (isset($specialCases[$field])) {
            return $specialCases[$field];
        }

        // Split camelCase and snake_case
        $result = preg_replace('/([a-z])([A-Z])/', '$1 $2', $field);
        $result = str_replace('_', ' ', $result);

        return ucwords($result);
    }

    /**
     * Set payload value.
     */
    public function setPayloadValue(string $key, $value): void
    {
        $payload = $this->payload ?? [];
        $payload[$key] = $value;
        $this->payload = $payload;
    }

    /**
     * Get all responses for this request form
     * Ordered by creation date (newest first).
     */
    public function responses(): HasMany
    {
        return $this->hasMany(RequestResponse::class, 'request_form_id', 'id')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get the latest response for this request form.
     */
    public function latestResponse()
    {
        return $this->hasOne(RequestResponse::class, 'request_form_id', 'id')
            ->latest('created_at');
    }

    /**
     * Get the user who last responded to this request.
     * This is a helper method that returns the responder from the latest response.
     */
    public function getLastResponderAttribute()
    {
        $latestResponse = $this->latestResponse;

        return $latestResponse ? $latestResponse->responder : null;
    }

    /**
     * Get the user who validated this request.
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = $model->generateUnique10DigitId();
            }
        });
    }

    /**
     * Generate a unique 10-digit number.
     * The ID will never start with 0 to avoid issues with numeric conversions.
     */
    private function generateUnique10DigitId(): string
    {
        do {
            // Generate a 10-digit number using timestamp + random component
            // First digit is always 1-9 to avoid leading zeros
            $firstDigit = mt_rand(1, 9);
            $timestamp = time();
            $random = mt_rand(1000, 9999);
            // Combine: 1 digit (1-9) + 5 digits from timestamp + 4 digits from random = 10 digits
            $idString = $firstDigit.substr($timestamp, -5).str_pad((string) $random, 4, '0', STR_PAD_LEFT);
        } while (static::where('id', $idString)->exists());

        return $idString;
    }

    /**
     * Format estado civil enum value.
     */
    private function formatEstadoCivil(string $value): string
    {
        return match ($value) {
            'soltero' => 'Soltero(a)',
            'casado' => 'Casado(a)',
            'union_libre' => 'Unión libre',
            'divorciado' => 'Divorciado(a)',
            'viudo' => 'Viudo(a)',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }

    /**
     * Format tipo cuenta enum value.
     */
    private function formatTipoCuenta(string $value): string
    {
        return match ($value) {
            'ahorros' => 'Ahorros',
            'corriente' => 'Corriente',
            default => ucfirst($value),
        };
    }

    /**
     * Format banco enum value.
     */
    private function formatBanco(string $value): string
    {
        return match ($value) {
            'bancolombia' => 'Bancolombia',
            'davivienda' => 'Davivienda',
            'bbva' => 'BBVA',
            'bogota' => 'Banco de Bogotá',
            'occidente' => 'Banco de Occidente',
            'popular' => 'Banco Popular',
            'av_villas' => 'AV Villas',
            'caja_social' => 'Caja Social',
            'colpatria' => 'Colpatria',
            'agrario' => 'Banco Agrario',
            'cooperativo' => 'Cooperativo',
            'otros' => 'Otros',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }

    /**
     * Format EPS enum value.
     */
    private function formatEps(string $value): string
    {
        return match ($value) {
            'sura' => 'SURA',
            'nueva_eps' => 'Nueva EPS',
            'sanitas' => 'Sanitas',
            'coomeva' => 'Coomeva',
            'compensar' => 'Compensar',
            'famisanar' => 'Famisanar',
            'savia' => 'Savia',
            'aliansalud' => 'Aliansalud',
            'otros' => 'Otros',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }

    /**
     * Format AFP enum value.
     */
    private function formatAfp(string $value): string
    {
        return match ($value) {
            'proteccion' => 'Protección',
            'porvenir' => 'Porvenir',
            'colfondos' => 'Colfondos',
            'colpensiones' => 'Colpensiones',
            'old_mutual' => 'Old Mutual',
            'skandia' => 'Skandia',
            'otros' => 'Otros',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }

    /**
     * Format nivel educativo enum value.
     */
    private function formatNivelEducativo(string $value): string
    {
        return match ($value) {
            'primaria' => 'Primaria',
            'bachiller' => 'Bachiller',
            'tecnico' => 'Tecnico',
            'tecnologo' => 'Tecnologo',
            'profesional' => 'Profesional',
            'especialista' => 'Especialista',
            'maestria' => 'Maestría',
            'doctorado' => 'Doctorado',
            default => ucfirst($value),
        };
    }

    /**
     * Format beneficiarios nuevos for display.
     */
    private function formatBeneficiariosNuevos(array $beneficiarios): string
    {
        if (empty($beneficiarios)) {
            return 'Ninguno';
        }

        $formatted = [];
        foreach ($beneficiarios as $index => $beneficiario) {
            $numero = $index + 1;
            $info = [];

            $info[] = "<strong>Beneficiario {$numero}:</strong>";
            $info[] = '• Nombre completo: '.($beneficiario['nombres'] ?? '').' '.($beneficiario['apellidos'] ?? '');
            $info[] = '• Tipo documento: '.($beneficiario['tipo_documento'] ?? '');
            $info[] = '• Documento: '.($beneficiario['documento'] ?? '');

            if (! empty($beneficiario['fecha_nacimiento'])) {
                $info[] = '• Fecha de nacimiento: '.$beneficiario['fecha_nacimiento'];
            }

            if (! empty($beneficiario['parentesco'])) {
                $info[] = '• Parentesco: '.$beneficiario['parentesco'];
            }

            if (! empty($beneficiario['sexo'])) {
                $info[] = '• Sexo: '.$beneficiario['sexo'];
            }

            $formatted[] = implode('<br>', $info);
        }

        return implode('<br><br>', $formatted);
    }

    /**
     * Format beneficiarios eliminados for display.
     */
    private function formatBeneficiariosEliminados(array $beneficiarios): string
    {
        if (empty($beneficiarios)) {
            return 'Ninguno';
        }

        $formatted = [];
        foreach ($beneficiarios as $index => $beneficiario) {
            $numero = $index + 1;
            $info = [];

            $info[] = "<strong>Beneficiario eliminado {$numero}:</strong>";
            $info[] = '• Nombre completo: '.($beneficiario['nombres'] ?? '').' '.($beneficiario['apellidos'] ?? '');
            $info[] = '• Tipo documento: '.($beneficiario['tipo_documento'] ?? '');
            $info[] = '• Documento: '.($beneficiario['documento'] ?? '');

            if (! empty($beneficiario['parentesco'])) {
                $info[] = '• Parentesco: '.$beneficiario['parentesco'];
            }

            if (! empty($beneficiario['sexo'])) {
                $info[] = '• Sexo: '.$beneficiario['sexo'];
            }

            $formatted[] = implode('<br>', $info);
        }

        return implode('<br><br>', $formatted);
    }

    /**
     * Format certificado info in a user-friendly way.
     */
    private function formatCertificadoInfo(array $certificadoData): string
    {
        $formatted = [];

        // Mapeo de campos a etiquetas en español
        $fieldLabels = [
            'fechaIngresoRetiro' => 'Fecha de ingreso/retiro',
            'valorCompensaciones' => 'Valor de compensaciones',
            'dirigidoAEntidad' => 'Dirigido a entidad',
            'paraSubsidioDesempleo' => 'Para subsidio de desempleo',
            'paraSubsidioVivienda' => 'Para subsidio de vivienda',
            'dirigidoFondoPensiones' => 'Dirigido a fondo de pensiones',
            'adicionarActividades' => 'Adicionar actividades',
            'dirigidoTransitoPicoPlaca' => 'Dirigido a tránsito pico y placa',
            'dirigidoBancolombia' => 'Dirigido a Bancolombia',
            'otros' => 'Otros',
        ];

        // Solo mostrar campos que están activos (true)
        foreach ($certificadoData as $field => $value) {
            if (! $this->parseBooleanValue($value)) {
                continue; // Saltar campos en false
            }

            $label = $fieldLabels[$field] ?? $this->formatFieldName($field);
            $formatted[] = "<strong>{$label}</strong>";
        }

        // Si no hay campos activos, mostrar mensaje
        if (empty($formatted)) {
            return '<span style="color:#6b7280;">No se seleccionaron opciones específicas</span>';
        }

        // Retornar como lista vertical compacta con mejor formato
        return '<div style="line-height:1.6; word-wrap:break-word; max-width:100%;">'.
               implode('<br>', $formatted).
               '</div>';
    }

    /**
     * Format array values in a user-friendly way.
     */
    private function formatArrayValue($value): string
    {
        if (is_array($value) && ! empty($value)) {
            // For simple arrays, join with commas
            if (array_keys($value) === range(0, count($value) - 1)) {
                return implode(', ', array_map(function ($item) {
                    return is_array($item) ? json_encode($item) : (string) $item;
                }, $value));
            }

            // For associative arrays, format as key: value pairs
            $pairs = [];
            foreach ($value as $k => $v) {
                $pairs[] = ucwords(str_replace('_', ' ', $k)).': '.(string) $v;
            }

            return implode('<br>', $pairs);
        }

        return (string) $value;
    }

    /**
     * Format a numeric value as COP currency without decimals.
     */
    private function formatCurrencyCOP($amount): string
    {
        if (! is_numeric($amount)) {
            return (string) $amount;
        }

        // Format as COP currency without decimals: $1.234.567
        return '$'.number_format((float) $amount, 0, ',', '.');
    }

    /**
     * Verifica si este RequestForm es un certificado de convenio simple
     * que será procesado automáticamente (solo fecha ingreso/retiro y/o dirigido a entidad)
     */
    public function esCertificadoConvenioSimple(): bool
    {
        // Solo verificar si es tipo certificado-convenio
        if ($this->request_type !== RequestTypes::CERTIFICADO_CONVENIO) {
            return false;
        }

        $payload = $this->payload ?? [];

        // Verificar si tiene infoCertificado en el payload
        if (! isset($payload['infoCertificado'])) {
            return false;
        }

        // Parsear el JSON string si existe
        $infoCertificado = $payload['infoCertificado'];
        if (is_string($infoCertificado)) {
            $infoCertificado = json_decode($infoCertificado, true);
        }

        if (! is_array($infoCertificado)) {
            return false;
        }

        // Verificar que solo tenga fechaIngresoRetiro y/o dirigidoAEntidad activos
        $fechaIngresoRetiro = $infoCertificado['fechaIngresoRetiro'] ?? false;
        $dirigidoAEntidad = $infoCertificado['dirigidoAEntidad'] ?? false;

        // Campos que NO deben estar activos para procesamiento automático
        $camposNoPermitidos = [
            'valorCompensaciones',
            'paraSubsidioDesempleo',
            'paraSubsidioVivienda',
            'dirigidoFondoPensiones',
            'adicionarActividades',
            'dirigidoBancolombia',
            'otros',
        ];

        // Verificar que ningún campo no permitido esté activo
        foreach ($camposNoPermitidos as $campo) {
            if (! empty($infoCertificado[$campo] ?? false)) {
                return false;
            }
        }

        // Si tiene fechaIngresoRetiro o dirigidoAEntidad activo, es un certificado simple
        return $fechaIngresoRetiro || $dirigidoAEntidad;
    }

    /**
     * Check if the request form includes bank information updates
     * Returns true if any of tipoCuenta, numeroCuenta, or banco fields are present in payload
     */
    public function hasBankInfoUpdate(): bool
    {
        // Only check for actualizar-datos-personales requests
        if ($this->request_type !== RequestTypes::ACTUALIZAR_DATOS_PERSONALES &&
            $this->request_type !== 'actualizar-datos-personales') {
            return false;
        }

        $payload = $this->payload ?? [];

        // Check if any bank-related fields are present and not empty
        $bankFields = ['tipoCuenta', 'numeroCuenta', 'banco'];

        foreach ($bankFields as $field) {
            if (isset($payload[$field]) && $payload[$field] !== '' && $payload[$field] !== null) {
                return true;
            }
        }

        return false;
    }
}
