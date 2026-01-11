<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $id
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
     */
    private function generateUnique10DigitId(): string
    {
        do {
            // Generate a 10-digit number using timestamp + random component
            $timestamp = time();
            $random = mt_rand(1000, 9999);
            $idString = substr($timestamp, -6) . $random;
            $idString = str_pad($idString, 10, '0', STR_PAD_LEFT);
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
     * Scope for surveys by hospital.
     */
    public function scopeByHospital(Builder $query, string $hospital): Builder
    {
        return $query->where('hospital', $hospital);
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
     * Get the translated document type in Spanish.
     */
    public function getTranslatedDocumentTypeAttribute(): string
    {
        $documentTypeLabels = [
            'CC' => 'Cédula de Ciudadanía',
            'CE' => 'Cédula de Extranjería',
            'TI' => 'Tarjeta de Identidad',
            'PA' => 'Pasaporte',
            'PT' => 'Pasaporte',
            'RC' => 'Registro Civil',
        ];

        $docType = strtoupper(trim($this->tipo_documento ?? ''));
        return $documentTypeLabels[$docType] ?? $this->tipo_documento;
    }
}
