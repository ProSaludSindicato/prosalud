<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryVariant extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'inventory_variants';

    protected $fillable = [
        'product_id',
        'size',
        'color_id',
        'stock',
        'min_stock',
        'max_stock',
        'sku',
    ];

    protected $casts = [
        'stock' => 'integer',
        'min_stock' => 'integer',
        'max_stock' => 'integer',
    ];

    /**
     * Get the product that owns this variant
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    /**
     * Get the color for this variant
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(InventoryColor::class, 'color_id');
    }

    /**
     * Get all hospital request items for this variant
     */
    public function hospitalRequestItems(): HasMany
    {
        return $this->hasMany(HospitalRequestItem::class, 'variant_id');
    }

    /**
     * Get all supplier delivery items for this variant
     */
    public function supplierDeliveryItems(): HasMany
    {
        return $this->hasMany(SupplierDeliveryItem::class, 'variant_id');
    }

    /**
     * Check if this variant is low on stock
     */
    public function getIsLowStockAttribute(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    /**
     * Get variant label (e.g., "M - Azul Rey")
     */
    public function getLabelAttribute(): string
    {
        $parts = [];

        if ($this->size) {
            $parts[] = $this->size;
        }

        if ($this->color) {
            $parts[] = $this->color->label;
        }

        return empty($parts) ? 'Estándar' : implode(' - ', $parts);
    }
}
