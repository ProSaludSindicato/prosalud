<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WellnessActivityEvidence extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'wellness_activity_evidences';

    protected $fillable = [
        'activity_realized_id',
        'image_url',
        'is_selected_for_gallery',
        'order',
    ];

    protected $casts = [
        'activity_realized_id' => 'integer',
        'is_selected_for_gallery' => 'boolean',
        'order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con la actividad realizada.
     */
    public function activityRealized(): BelongsTo
    {
        return $this->belongsTo(WellnessActivityRealized::class, 'activity_realized_id');
    }
}
