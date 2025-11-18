<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierDeliveryItem extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'supplier_delivery_items';

    protected $fillable = [
        'supplier_delivery_id',
        'product_id',
        'variant_id',
        'quantity',
        'received',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'received' => 'integer',
    ];

    /**
     * Get the supplier delivery that owns this item.
     */
    public function supplierDelivery(): BelongsTo
    {
        return $this->belongsTo(SupplierDelivery::class, 'supplier_delivery_id');
    }

    /**
     * Get the product for this item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    /**
     * Get the variant for this item.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(InventoryVariant::class, 'variant_id');
    }
}
