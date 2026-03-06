<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tipo de entrega de bienestar (administrable).
 * Define nombre visible, si está activo y el rango de fechas en que aplica.
 * Las solicitudes creadas en una fecha dentro del rango usan este tipo.
 *
 * @property int $id
 * @property string $nombre
 * @property bool $activo
 * @property string $modo_acceso  'listado' | 'abierto'
 * @property Carbon $fecha_desde
 * @property Carbon $fecha_hasta
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $createdBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WellnessDeliveryRequest> $requests
 */
class WellnessDeliveryType extends Model
{
    use HasFactory;

    protected $table = 'wellness_delivery_types';

    /**
     * listado = con registro: requiere Excel con lista de afiliados permitidos; se valida al reclamar.
     * abierto = público: cualquier afiliado puede reclamar sin listado (ej. día de la mujer).
     */
    public const MODOS_ACCESO = [
        'listado' => 'Con listado (requiere Excel de afiliados permitidos)',
        'abierto' => 'Abierto (cualquier afiliado activo)',
    ];

    protected $fillable = [
        'nombre',
        'activo',
        'modo_acceso',
        'fecha_desde',
        'fecha_hasta',
        'created_by',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'fecha_desde' => 'date',
        'fecha_hasta' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope: tipo activo para una fecha (la fecha cae dentro del rango y activo = true).
     * Usa comparación directa con la columna DATE para evitar problemas con whereDate en distintos drivers.
     */
    public function scopeActivoParaFecha($query, $date): void
    {
        $d = $date instanceof \DateTimeInterface
            ? Carbon::parse($date)->toDateString()
            : Carbon::parse($date)->toDateString();

        $query->where('activo', true)
            ->where('fecha_desde', '<=', $d)
            ->where('fecha_hasta', '>=', $d);
    }

    /**
     * Obtener el tipo de entrega activo para una fecha.
     * Si hay varios (rangos solapados), se devuelve el más reciente por fecha_desde.
     */
    public static function getActivoParaFecha($date): ?self
    {
        return self::activoParaFecha($date)
            ->orderByDesc('fecha_desde')
            ->first();
    }

    /** True si este tipo requiere cargar Excel y validar que el afiliado esté en el listado. */
    public function requiereListado(): bool
    {
        return $this->modo_acceso === 'listado';
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(WellnessDeliveryRequest::class, 'wellness_delivery_type_id');
    }
}
