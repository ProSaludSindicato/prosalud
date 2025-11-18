<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class InventorySubcategory extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'inventory_subcategories';

    protected $fillable = [
        'category_id',
        'name',
        'description',
    ];

    /**
     * Get the category that owns this subcategory.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    /**
     * Get all products for this subcategory.
     */
    public function products(): HasMany
    {
        return $this->hasMany(InventoryProduct::class, 'subcategory_id');
    }
}
