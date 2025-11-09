<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SstDeliveryRecord extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sst_delivery_records';

    protected $fillable = [
        'id',
        'affiliate_id',
        'affiliate_document_type',
        'affiliate_document_number',
        'affiliate_first_name',
        'affiliate_last_name',
        'affiliate_hospital',
        'affiliate_role',
        'affiliate_status',
        'delivered_by_user_id',
        'delivered_by_name',
        'delivered_at',
        'signature_path',
        'signature_mime_type',
        'signed_document_type',
        'signed_document_number',
        'notes',
        'delivery_type',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SstDeliveryItem::class, 'delivery_id');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }

    public function getAffiliateFullNameAttribute(): ?string
    {
        $parts = array_filter([
            $this->affiliate_first_name,
            $this->affiliate_last_name,
        ]);

        return empty($parts) ? null : implode(' ', $parts);
    }
}
