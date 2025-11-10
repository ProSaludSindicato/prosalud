<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierDelivery extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'supplier_deliveries';

    protected $fillable = [
        'supplier_name',
        'delivery_date',
        'total_items',
        'status',
        'delivery_type',
        'notes',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'total_items' => 'integer',
    ];

    /**
     * Get all items for this delivery
     */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierDeliveryItem::class, 'supplier_delivery_id');
    }
}
