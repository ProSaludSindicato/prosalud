<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SstReturnItem extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sst_return_items';

    protected $fillable = [
        'id',
        'return_id',
        'item_id',
        'item_name',
        'item_category',
        'item_gender',
        'unit',
        'variant_color',
        'variant_size',
        'variant_payload',
        'quantity',
    ];

    protected $casts = [
        'variant_payload' => 'array',
    ];

    public function returnRecord(): BelongsTo
    {
        return $this->belongsTo(SstReturnRecord::class, 'return_id');
    }
}
