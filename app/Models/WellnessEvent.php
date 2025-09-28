<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WellnessEvent extends Model
{
    protected $guarded = [];

    /**
     * The model does not have created_at/updated_at columns
     */
    public $timestamps = false;

    /**
     * Attribute casting
     */
    protected $casts = [
        'date' => 'date',
        'is_visible' => 'boolean',
        'attendees' => 'integer',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(WellnessEventImage::class, 'event_id');
    }
}
