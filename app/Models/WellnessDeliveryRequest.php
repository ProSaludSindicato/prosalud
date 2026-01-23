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
 * @property string $tipo_entrega
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
 */
class WellnessDeliveryRequest extends Model
{
    use HasFactory;

    protected $table = 'wellness_delivery_requests';

    protected $fillable = [
        'tipo_entrega',
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

    protected $casts = [
        'beneficiarios' => 'array',
        'cantidad_entregada' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Tipos de entrega disponibles
     */
    public const TIPOS_ENTREGA = [
        'kit_escolar' => 'Kit Escolar',
        'desayuno' => 'Desayuno',
        'lonchera' => 'Lonchera',
        'otro' => 'Otro',
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
     * Obtener el texto del tipo de entrega
     */
    public function getTipoEntregaTextAttribute(): string
    {
        return self::TIPOS_ENTREGA[$this->tipo_entrega] ?? $this->tipo_entrega;
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
        if (empty($this->beneficiarios) || !is_array($this->beneficiarios)) {
            return '';
        }

        $nombres = array_map(function ($beneficiario) {
            return $beneficiario['beneficiario'] ?? $beneficiario['nombre'] ?? '';
        }, $this->beneficiarios);

        return implode(', ', array_filter($nombres));
    }

    /**
     * Scope para filtrar por tipo de entrega
     */
    public function scopePorTipoEntrega($query, string $tipo)
    {
        return $query->where('tipo_entrega', $tipo);
    }

    /**
     * Scope para filtrar por estado
     */
    public function scopePorEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    /**
     * Scope para filtrar por documento
     */
    public function scopePorDocumento($query, string $documento)
    {
        return $query->where('documento_afiliado', $documento);
    }

    /**
     * Obtener el usuario que realizó la entrega o cancelación
     */
    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por_user_id');
    }
}

