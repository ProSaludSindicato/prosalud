<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
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
 * @property string $modo_acceso 'listado' | 'abierto' | 'entrega_listado'
 * @property string|null $listado_archivo_nombre
 * @property Carbon|null $listado_cargado_at
 * @property int|null $listado_cargado_por
 * @property Carbon|null $fecha_desde
 * @property Carbon|null $fecha_hasta
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $createdBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WellnessDeliveryRequest> $requests
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WellnessDeliveryListEntry> $listEntries
 */
class WellnessDeliveryType extends Model
{
    use HasFactory;

    protected $table = 'wellness_delivery_types';

    public const MODO_LISTADO = 'listado';

    public const MODO_ABIERTO = 'abierto';

    public const MODO_ENTREGA_LISTADO = 'entrega_listado';

    /**
     * listado = inscripción: el afiliado se inscribe y luego se le entrega; valida contra el Excel general.
     * abierto = cualquier afiliado activo; el personal registra la entrega en el acto (ej. día de la mujer).
     * entrega_listado = el personal registra la entrega en el acto, validando contra el listado de la campaña
     *                   y controlando los cupos por afiliado/beneficiario (ej. dulces de halloween).
     */
    public const MODOS_ACCESO = [
        self::MODO_LISTADO => 'Inscripción con listado (el afiliado se inscribe y luego recibe)',
        self::MODO_ABIERTO => 'Abierto (cualquier afiliado activo)',
        self::MODO_ENTREGA_LISTADO => 'Entrega directa con listado (cupos por afiliado)',
    ];

    protected $fillable = [
        'nombre',
        'activo',
        'modo_acceso',
        'listado_archivo_nombre',
        'listado_cargado_at',
        'listado_cargado_por',
        'fecha_desde',
        'fecha_hasta',
        'created_by',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'fecha_desde' => 'date',
        'fecha_hasta' => 'date',
        'listado_cargado_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope: tipos activos para una fecha.
     * - Siempre activos: activo=true y fecha_desde/fecha_hasta son null (ej. detalles de cumpleaños).
     * - Con rango: fecha_desde y fecha_hasta no null y la fecha cae dentro del rango (no se usa activo).
     */
    public function scopeActivoParaFecha($query, $date): void
    {
        $d = $date instanceof \DateTimeInterface
            ? Carbon::parse($date)->toDateString()
            : Carbon::parse($date)->toDateString();

        $query->where(function ($q) use ($d) {
            $q->where(function ($q2) {
                $q2->where('activo', true)
                    ->whereNull('fecha_desde')
                    ->whereNull('fecha_hasta');
            })->orWhere(function ($q2) use ($d) {
                $q2->whereNotNull('fecha_desde')
                    ->whereNotNull('fecha_hasta')
                    ->where('fecha_desde', '<=', $d)
                    ->where('fecha_hasta', '>=', $d);
            });
        });
    }

    /**
     * Obtener todos los tipos de entrega activos para una fecha (pueden ser varios).
     * Orden: siempre activos primero (por nombre), luego por fecha_desde desc.
     */
    public static function getActivosParaFecha($date): Collection
    {
        return self::activoParaFecha($date)
            ->orderByRaw('CASE WHEN fecha_desde IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('fecha_desde')
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Obtener un tipo de entrega activo para una fecha (compatibilidad).
     * Devuelve el primero de la lista cuando hay varios.
     */
    public static function getActivoParaFecha($date): ?self
    {
        return self::getActivosParaFecha($date)->first();
    }

    /** True si este tipo es siempre activo (sin rango de fechas). */
    public function isSiempreActivo(): bool
    {
        return $this->fecha_desde === null && $this->fecha_hasta === null;
    }

    /** True si este tipo requiere cargar Excel y validar que el afiliado esté en el listado. */
    public function requiereListado(): bool
    {
        return $this->modo_acceso === self::MODO_LISTADO;
    }

    /** True si el personal entrega en el acto validando contra el listado propio de la campaña. */
    public function esEntregaConListado(): bool
    {
        return $this->modo_acceso === self::MODO_ENTREGA_LISTADO;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function listadoCargadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'listado_cargado_por');
    }

    public function listEntries(): HasMany
    {
        return $this->hasMany(WellnessDeliveryListEntry::class, 'wellness_delivery_type_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(WellnessDeliveryRequest::class, 'wellness_delivery_type_id');
    }
}
