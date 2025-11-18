<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WellnessEventImage extends Model
{
    /**
     * The model does not have created_at/updated_at columns.
     */
    public $timestamps = false;
    protected $guarded = [];

    public function event(): BelongsTo
    {
        return $this->belongsTo(WellnessEvent::class, 'event_id');
    }
}
