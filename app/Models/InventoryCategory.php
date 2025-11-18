<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCategory extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'inventory_categories';

    protected $fillable = [
        'name',
        'description',
        'icon',
    ];

    /**
     * Get all subcategories for this category.
     */
    public function subcategories(): HasMany
    {
        return $this->hasMany(InventorySubcategory::class, 'category_id');
    }

    /**
     * Get all products for this category.
     */
    public function products(): HasMany
    {
        return $this->hasMany(InventoryProduct::class, 'category_id');
    }
}
