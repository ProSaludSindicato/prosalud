<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryProduct extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'inventory_products';

    protected $fillable = [
        'name',
        'category_id',
        'subcategory_id',
        'description',
        'variant_mode',
        'gender',
    ];

    /**
     * Get the category that owns this product
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    /**
     * Get the subcategory that owns this product
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(InventorySubcategory::class, 'subcategory_id');
    }

    /**
     * Get all variants for this product
     */
    public function variants(): HasMany
    {
        return $this->hasMany(InventoryVariant::class, 'product_id');
    }

    /**
     * Get all hospital request items for this product
     */
    public function hospitalRequestItems(): HasMany
    {
        return $this->hasMany(HospitalRequestItem::class, 'product_id');
    }

    /**
     * Get all supplier delivery items for this product
     */
    public function supplierDeliveryItems(): HasMany
    {
        return $this->hasMany(SupplierDeliveryItem::class, 'product_id');
    }

    /**
     * Calculate total stock across all variants
     */
    public function getTotalStockAttribute(): int
    {
        return $this->variants()->sum('stock');
    }

    /**
     * Check if any variant is low on stock
     */
    public function getIsLowStockAttribute(): bool
    {
        return $this->variants()
            ->whereColumn('stock', '<=', 'min_stock')
            ->exists();
    }
}
