<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Modelo para solicitudes de entregas de bienestar (kits escolares, desayunos, loncheras, etc.)
 *
 * @property int $id
 * @property int|null $wellness_delivery_type_id
 * @property string $tipo_entrega
 * @property string|null $tipo_entrega_descripcion
 * @property string $documento_afiliado
 * @property string $nombre_afiliado
 * @property string|null $hospital
 * @property string $fecha_expedicion
 * @property array $beneficiarios
 * @property string $firma
 * @property string|null $firma_recibido
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $estado
 * @property int|null $cantidad_entregada
 * @property string|null $observaciones
 * @property int|null $entregado_por_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $entregadoPor
 * @property-read WellnessDeliveryType|null $tipoEntrega
 */
class WellnessDeliveryRequest extends Model
{
    use HasFactory;

    protected $table = 'wellness_delivery_requests';

    protected $fillable = [
        'wellness_delivery_type_id',
        'tipo_entrega',
        'tipo_entrega_descripcion',
        'documento_afiliado',
        'nombre_afiliado',
        'hospital',
        'fecha_expedicion',
        'beneficiarios',
        'firma',
        'firma_recibido',
        'ip_address',
        'user_agent',
        'estado',
        'cantidad_entregada',
        'observaciones',
        'entregado_por_user_id',
    ];

    protected $attributes = [
        'cantidad_entregada' => 1,
    ];

    protected $casts = [
        'beneficiarios' => 'array',
        'cantidad_entregada' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Estados disponibles
     */
    public const ESTADOS = [
        'pendiente' => 'Pendiente',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelado',
    ];

    /**
     * Obtener el texto del tipo de entrega (nombre tal como se muestra al usuario).
     * Prioridad: tipo asociado (wellnessDeliveryType) > tipo_entrega_descripcion (legacy) > tipo_entrega (legacy).
     */
    public function getTipoEntregaTextAttribute(): string
    {
        if ($this->relationLoaded('tipoEntrega') && $this->tipoEntrega) {
            return $this->tipoEntrega->nombre;
        }
        if ($this->wellness_delivery_type_id) {
            $type = $this->tipoEntrega;
            if ($type) {
                return $type->nombre;
            }
        }
        if (! empty($this->tipo_entrega_descripcion)) {
            return $this->tipo_entrega_descripcion;
        }

        return $this->tipo_entrega ?? '';
    }

    /**
     * Obtener el texto del estado
     */
    public function getEstadoTextAttribute(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    /**
     * Obtener la lista de nombres de beneficiarios como string
     */
    public function getBeneficiariosNombresAttribute(): string
    {
        if (empty($this->beneficiarios) || ! is_array($this->beneficiarios)) {
            return '';
        }

        $nombres = array_map(function ($beneficiario) {
            return $beneficiario['beneficiario'] ?? $beneficiario['nombre'] ?? '';
        }, $this->beneficiarios);

        return implode(', ', array_filter($nombres));
    }

    /**
     * Scope para filtrar por tipo de entrega (id del tipo administrable)
     */
    public function scopePorTipoEntrega($query, $tipo)
    {
        if (is_numeric($tipo)) {
            return $query->where('wellness_delivery_type_id', (int) $tipo);
        }

        return $query->where('tipo_entrega', $tipo);
    }

    /**
     * Tipo de entrega asociado (cuando la solicitud usa tipos administrables).
     */
    public function tipoEntrega(): BelongsTo
    {
        return $this->belongsTo(WellnessDeliveryType::class, 'wellness_delivery_type_id');
    }

    /**
     * Scope para filtrar por estado
     */
    public function scopePorEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    /**
     * Scope para filtrar por documento.
     * Permite búsquedas parciales por número de documento.
     */
    public function scopePorDocumento($query, string $documento)
    {
        $search = trim($documento);

        if ($search === '') {
            return $query;
        }

        return $query->where('documento_afiliado', 'like', '%'.$search.'%');
    }

    /**
     * Scope para filtrar por solicitante (nombre del afiliado).
     * Permite búsquedas parciales por nombre.
     */
    public function scopePorSolicitante($query, string $solicitante)
    {
        $search = trim($solicitante);

        if ($search === '') {
            return $query;
        }

        return $query->where('nombre_afiliado', 'like', '%'.$search.'%');
    }

    /**
     * Obtener el usuario que realizó la entrega o cancelación
     */
    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por_user_id');
    }
}
