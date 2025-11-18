<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int                        $id
 * @property int                        $wellness_request_id
 * @property string                     $type
 * @property int                        $quantity
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property WellnessRequest            $wellnessRequest
 */
class WellnessRequestDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'wellness_request_id',
        'type',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con la solicitud de bienestar.
     */
    public function wellnessRequest(): BelongsTo
    {
        return $this->belongsTo(WellnessRequest::class, 'wellness_request_id');
    }
}
