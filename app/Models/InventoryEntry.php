<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryEntry extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'inventory_entries';

    protected $fillable = [
        'id',
        'supplier_id',
        'supplier_name',
        'received_at',
        'document_number',
        'notes',
        'created_by',
        'created_by_user_id',
        'total_items',
        'total_quantity',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'total_items' => 'integer',
        'total_quantity' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryEntryItem::class, 'entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
