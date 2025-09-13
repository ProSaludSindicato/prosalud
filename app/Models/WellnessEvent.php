<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WellnessEvent extends Model
{
    protected $guarded = [];

    public function images(): HasMany
    {
        return $this->hasMany(WellnessEventImage::class, 'event_id');
    }
}
