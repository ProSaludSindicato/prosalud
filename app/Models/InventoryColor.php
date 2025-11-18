<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryColor extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'inventory_colors';

    protected $fillable = [
        'id',
        'label',
        'hex',
    ];

    /**
     * Get all variants with this color.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(InventoryVariant::class, 'color_id');
    }
}
