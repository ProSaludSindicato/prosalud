<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestSubtypeAssignment extends Model
{
    protected $fillable = [
        'request_type',
        'subtype',
        'user_id',
    ];

    /**
     * Get the user that is assigned to this request subtype
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
