<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryVariantStock extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'variant_id',
        'location_id',
        'stock',
        'reserved',
        'min_stock',
        'max_stock',
    ];

    protected $casts = [
        'stock' => 'integer',
        'reserved' => 'integer',
        'min_stock' => 'integer',
        'max_stock' => 'integer',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(InventoryVariant::class, 'variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }
}
