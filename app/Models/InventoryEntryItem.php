<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryEntryItem extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'inventory_entry_items';

    protected $fillable = [
        'id',
        'entry_id',
        'product_id',
        'variant_id',
        'product_name',
        'variant_label',
        'quantity',
        'previous_stock',
        'new_stock',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'previous_stock' => 'integer',
        'new_stock' => 'integer',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(InventoryEntry::class, 'entry_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(InventoryVariant::class, 'variant_id');
    }
}
