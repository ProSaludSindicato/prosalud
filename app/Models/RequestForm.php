<?php

namespace App\Models;

use App\Constants\{RequestStatuses, RequestTypes};
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $request_type
 * @property string      $document_type
 * @property string      $document_number
 * @property string      $name
 * @property string      $last_name
 * @property string      $email
 * @property string      $phone_number
 * @property array|null  $payload
 * @property array|null  $files
 * @property string      $status
 * @property string      $created_at
 * @property string|null $processed_at
 * @property string      $full_name
 * @property string      $formatted_created_at
 * @property string      $formatted_processed_at
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
        'processed_at',
    ];

    protected $casts = [
        'id' => 'string',
        'payload' => 'array',
        'files' => 'array',
        'created_at' => 'datetime',
        'processed_at' => 'datetime',
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
        return trim($this->name . ' ' . $this->last_name);
    }

    /**
     * Get formatted created_at attribute.
     */
    public function getFormattedCreatedAtAttribute(): string
    {
        if (!$this->created_at) {
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
        if (!$this->processed_at) {
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
        return 'pending' === $this->status;
    }

    /**
     * Check if request is processed.
     */
    public function isProcessed(): bool
    {
        return 'processed' === $this->status;
    }

    /**
     * Get payload value by key.
     */
    public function getPayloadValue(string $key, $default = null)
    {
        return $this->payload[$key] ?? $default;
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
     * Format payload value for display in email.
     */
    public function formatPayloadValue(string $key, $value): string
    {
        // Special handling for certificado info
        if ('infoCertificado' === $key) {
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
        if ('beneficiariosNuevos' === $key && is_array($value)) {
            return $this->formatBeneficiariosNuevos($value);
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
            return 0 !== (int) $value;
        }

        if (is_array($value)) {
            return !empty($value);
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
            // Update data fields
            'dondeRealizaProceso' => 'Donde realiza el proceso',
            'estadoCivil' => 'Estado civil',
            'direccion' => 'Dirección',
            'municipio' => 'Municipio',
            'telefonoFijo' => 'Teléfono fijo',
            'celular' => 'Celular',
            'correo' => 'Correo electrónico',
            'tallaUniforme' => 'Talla de uniforme',
            'tallaCalzado' => 'Talla de calzado',
            'nivelEducativo' => 'Nivel educativo',
            'numeroCuenta' => 'Número de cuenta',
            'tipoCuenta' => 'Tipo de cuenta',
            'banco' => 'Banco',
            'eps' => 'EPS',
            'afp' => 'AFP',
            'beneficiariosNuevos' => 'Beneficiarios nuevos',
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
     */
    private function generateUnique10DigitId(): string
    {
        do {
            // Generate a 10-digit number using timestamp + random component
            $timestamp = time(); // 10 digits, but we'll use last 6
            $random = mt_rand(1000, 9999); // 4 digits

            // Combine to create exactly 10 digits
            $idString = substr($timestamp, -6) . $random; // 6 + 4 = 10 digits

            // Ensure it's exactly 10 digits by padding if needed
            $idString = str_pad($idString, 10, '0', STR_PAD_LEFT);
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
            $info[] = '• Nombre completo: ' . ($beneficiario['nombres'] ?? '') . ' ' . ($beneficiario['apellidos'] ?? '');
            $info[] = '• Tipo documento: ' . ($beneficiario['tipo_documento'] ?? '');
            $info[] = '• Documento: ' . ($beneficiario['documento'] ?? '');

            if (!empty($beneficiario['fecha_nacimiento'])) {
                $info[] = '• Fecha de nacimiento: ' . $beneficiario['fecha_nacimiento'];
            }

            if (!empty($beneficiario['parentesco'])) {
                $info[] = '• Parentesco: ' . $beneficiario['parentesco'];
            }

            if (!empty($beneficiario['sexo'])) {
                $info[] = '• Sexo: ' . $beneficiario['sexo'];
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
            if (!$this->parseBooleanValue($value)) {
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
        return '<div style="line-height:1.6; word-wrap:break-word; max-width:100%;">' . 
               implode('<br>', $formatted) . 
               '</div>';
    }

    /**
     * Format array values in a user-friendly way.
     */
    private function formatArrayValue($value): string
    {
        if (is_array($value) && !empty($value)) {
            // For simple arrays, join with commas
            if (array_keys($value) === range(0, count($value) - 1)) {
                return implode(', ', array_map(function ($item) {
                    return is_array($item) ? json_encode($item) : (string) $item;
                }, $value));
            }

            // For associative arrays, format as key: value pairs
            $pairs = [];
            foreach ($value as $k => $v) {
                $pairs[] = ucwords(str_replace('_', ' ', $k)) . ': ' . (string) $v;
            }

            return implode('<br>', $pairs);
        }

        return (string) $value;
    }

    /**
     * Verifica si este RequestForm es un certificado de convenio simple
     * que será procesado automáticamente (solo fecha ingreso/retiro y/o dirigido a entidad)
     * 
     * @return bool
     */
    public function esCertificadoConvenioSimple(): bool
    {
        // Solo verificar si es tipo certificado-convenio
        if ($this->request_type !== RequestTypes::CERTIFICADO_CONVENIO) {
            return false;
        }

        $payload = $this->payload ?? [];
        
        // Verificar si tiene infoCertificado en el payload
        if (!isset($payload['infoCertificado'])) {
            return false;
        }

        // Parsear el JSON string si existe
        $infoCertificado = $payload['infoCertificado'];
        if (is_string($infoCertificado)) {
            $infoCertificado = json_decode($infoCertificado, true);
        }

        if (!is_array($infoCertificado)) {
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
            if (!empty($infoCertificado[$campo] ?? false)) {
                return false;
            }
        }

        // Si tiene fechaIngresoRetiro o dirigidoAEntidad activo, es un certificado simple
        return $fechaIngresoRetiro || $dirigidoAEntidad;
    }
}
