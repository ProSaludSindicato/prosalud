<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestTypeAssignment extends Model
{
    protected $fillable = [
        'request_type',
        'user_id',
    ];

    /**
     * Get the user that is assigned to this request type
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
