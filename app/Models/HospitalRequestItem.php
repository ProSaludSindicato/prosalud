<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalRequestItem extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'hospital_request_items';

    protected $fillable = [
        'hospital_request_id',
        'product_id',
        'variant_id',
        'variant_label',
        'size',
        'color_id',
        'quantity',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    /**
     * Get the hospital request that owns this item
     */
    public function hospitalRequest(): BelongsTo
    {
        return $this->belongsTo(HospitalRequest::class, 'hospital_request_id');
    }

    /**
     * Get the product for this item
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    /**
     * Get the variant for this item
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(InventoryVariant::class, 'variant_id');
    }
}
