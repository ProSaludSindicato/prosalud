<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cupo del listado de una campaña de entregas (modo entrega_listado).
 * Una fila por beneficiario (o por afiliado si no hay beneficiarios) con la cantidad a entregar.
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
