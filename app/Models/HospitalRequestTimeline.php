<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalRequestTimeline extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'hospital_request_timeline';

    protected $fillable = [
        'hospital_request_id',
        'status',
        'timestamp',
        'description',
        'actor',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    /**
     * Get the hospital request that owns this timeline entry
     */
    public function hospitalRequest(): BelongsTo
    {
        return $this->belongsTo(HospitalRequest::class, 'hospital_request_id');
    }
}
