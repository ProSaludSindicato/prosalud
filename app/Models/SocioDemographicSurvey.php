<?php

namespace App\Models;

use App\Helpers\SurveyFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $survey_type
 * @property string $correo
 * @property string $tipo_documento
 * @property string $numero_documento
 * @property string|null $nombres
 * @property string|null $apellidos
 * @property string $hospital
 * @property string $profesion
 * @property string|null $rh
 * @property string|null $fecha_expedicion
 * @property string|null $lugar_nacimiento
 * @property string|null $departamento
 * @property string|null $celular
 * @property string|null $direccion
 * @property string|null $municipio
 * @property string|null $barrio
 * @property string|null $talla_calzado
 * @property string|null $talla_vestimenta
 * @property string $pais_nacimiento
 * @property string|null $nombre_contacto_emergencia
 * @property string|null $relacion_contacto_emergencia
 * @property string|null $telefono_contacto_emergencia
 * @property array $datos_sociodemograficos
 * @property array $datos_consumo
 * @property array $condiciones_salud
 * @property array $limitaciones_fisicas
 * @property string $recomendacion_restriccion_laboral
 * @property string|null $detalle_recomendacion_laboral
 * @property string|null $firma_path
 * @property string $numero_documento_firma
 * @property string $created_at
 * @property string|null $updated_at
 */
class SocioDemographicSurvey extends Model
{
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'survey_type',
        'correo',
        'tipo_documento',
        'numero_documento',
        'nombres',
        'apellidos',
        'hospital',
        'profesion',
        'rh',
        'fecha_expedicion',
        'lugar_nacimiento',
        'departamento',
        'celular',
        'direccion',
        'municipio',
        'barrio',
        'talla_calzado',
        'talla_vestimenta',
        'pais_nacimiento',
        'nombre_contacto_emergencia',
        'relacion_contacto_emergencia',
        'telefono_contacto_emergencia',
        'datos_sociodemograficos',
        'datos_consumo',
        'condiciones_salud',
        'limitaciones_fisicas',
        'recomendacion_restriccion_laboral',
        'detalle_recomendacion_laboral',
        'firma_path',
        'numero_documento_firma',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'datos_sociodemograficos' => 'array',
        'datos_consumo' => 'array',
        'condiciones_salud' => 'array',
        'limitaciones_fisicas' => 'array',
        'fecha_expedicion' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getDireccionCompletaAttribute(): string
    {
        return SurveyFormatter::formatDireccionConBarrio($this->direccion, $this->barrio);
    }

    /**
     * Retrieve the model for route model binding.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::where('id', (string) $value)->firstOrFail();
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
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });

        static::updating(function ($model) {
            $model->updated_at = now();
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
     * Scope for surveys by document number.
     */
    public function scopeByDocument(Builder $query, string $tipoDocumento, string $numeroDocumento): Builder
    {
        return $query->where('tipo_documento', $tipoDocumento)
            ->where('numero_documento', $numeroDocumento);
    }

    /**
     * Scope for surveys by document number only (partial match).
     *
     * This is useful for API filters where the document type is not provided
     * and the user may search by a fragment of the document number.
     */
    public function scopeByDocumentNumber(Builder $query, string $numeroDocumento): Builder
    {
        $numeroDocumento = trim($numeroDocumento);

        if ($numeroDocumento === '') {
            return $query;
        }

        return $query->where('numero_documento', 'LIKE', '%'.$numeroDocumento.'%');
    }

    /**
     * Scope for surveys by hospital (coincidencia al inicio para reducir fricción).
     */
    public function scopeByHospital(Builder $query, string $hospital): Builder
    {
        $hospital = trim($hospital);
        if ($hospital === '') {
            return $query;
        }

        return $query->where('hospital', 'LIKE', $hospital.'%');
    }

    /**
     * Scope for surveys by multiple hospitals (selección múltiple, coincidencia exacta).
     */
    public function scopeByHospitals(Builder $query, array $hospitals): Builder
    {
        $hospitals = array_values(array_filter(array_map('trim', $hospitals)));
        if (empty($hospitals)) {
            return $query;
        }

        return $query->whereIn('hospital', $hospitals);
    }

    /**
     * Scope for surveys by year (created_at).
     */
    public function scopeByYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('created_at', $year);
    }

    /**
     * Scope for surveys by month (created_at).
     */
    public function scopeByMonth(Builder $query, int $month): Builder
    {
        return $query->whereMonth('created_at', $month);
    }

    /**
     * Scope for surveys by labor restriction answer (si / no).
     */
    public function scopeByLaborRestriction(Builder $query, string $value): Builder
    {
        $value = strtolower(trim($value));

        if ($value === 'si') {
            return $query->whereRaw('LOWER(recomendacion_restriccion_laboral) = ?', ['si']);
        }

        if ($value === 'no') {
            return $query->whereRaw('LOWER(recomendacion_restriccion_laboral) = ?', ['no']);
        }

        return $query;
    }

    /**
     * Whether the affiliate reported a labor recommendation or restriction.
     */
    public function hasLaborRestriction(): bool
    {
        return strtolower($this->recomendacion_restriccion_laboral ?? '') === 'si';
    }

    /**
     * Scope for surveys by name (searches in nombres, apellidos, or both).
     */
    public function scopeByName(Builder $query, string $name): Builder
    {
        $name = trim($name);
        if (empty($name)) {
            return $query;
        }

        return $query->where(function ($query) use ($name) {
            // Search in nombres field
            $query->where('nombres', 'LIKE', "%{$name}%")
                  // Search in apellidos field
                ->orWhere('apellidos', 'LIKE', "%{$name}%")
                  // Search in concatenated full name (nombres + ' ' + apellidos)
                ->orWhereRaw("CONCAT(COALESCE(nombres, ''), ' ', COALESCE(apellidos, '')) LIKE ?", ["%{$name}%"]);
        });
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

        $docType = strtoupper(trim($this->tipo_documento ?? ''));

        return $documentTypeLabels[$docType] ?? $this->tipo_documento;
    }
}
