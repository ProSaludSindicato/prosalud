<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class SstReturnRecord extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sst_return_records';

    protected $fillable = [
        'id',
        'affiliate_id',
        'affiliate_document_type',
        'affiliate_document_number',
        'affiliate_first_name',
        'affiliate_last_name',
        'affiliate_hospital',
        'affiliate_role',
        'received_by_user_id',
        'received_by_name',
        'returned_at',
        'signature_path',
        'signature_mime_type',
        'signed_document_type',
        'signed_document_number',
        'reason',
        'notes',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SstReturnItem::class, 'return_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
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
