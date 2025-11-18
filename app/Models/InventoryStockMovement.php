<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};

class InventoryStockMovement extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'variant_id',
        'from_location_id',
        'to_location_id',
        'quantity',
        'reason',
        'reference_type',
        'reference_id',
        'notes',
        'moved_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'moved_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(InventoryVariant::class, 'variant_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'to_location_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
