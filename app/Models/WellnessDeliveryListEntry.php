<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fila del listado de una campaña: cupo a entregar (modo entrega_listado) o persona habilitada para inscribirse (modo listado).
 * Una fila por beneficiario (o por persona si no hay beneficiarios).
 *
 * @property int $id
 * @property int $wellness_delivery_type_id
 * @property string $documento_afiliado
 * @property string $clave
 * @property string|null $nombre_afiliado
 * @property string|null $hospital
 * @property string|null $beneficiario
 * @property string|null $parentesco
 * @property string|null $edad
 * @property string|null $fecha_expedicion
 * @property int $cantidad
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WellnessDeliveryType $tipoEntrega
 */
class WellnessDeliveryListEntry extends Model
{
    /** @use HasFactory<\Database\Factories\WellnessDeliveryListEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'wellness_delivery_type_id',
        'documento_afiliado',
        'clave',
        'nombre_afiliado',
        'hospital',
        'beneficiario',
        'parentesco',
        'edad',
        'fecha_expedicion',
        'cantidad',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
        ];
    }

    public function tipoEntrega(): BelongsTo
    {
        return $this->belongsTo(WellnessDeliveryType::class, 'wellness_delivery_type_id');
    }
}
