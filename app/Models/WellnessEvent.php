<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};

class WellnessEvent extends Model
{
    /**
     * The model does not have created_at/updated_at columns.
     */
    public $timestamps = false;
    protected $guarded = [];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'date' => 'date',
        'is_visible' => 'boolean',
        'attendees' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(WellnessEventImage::class, 'event_id');
    }

    /**
     * Relación con la actividad realizada (si este evento fue creado desde una actividad).
     */
    public function activityRealized(): HasOne
    {
        return $this->hasOne(WellnessActivityRealized::class, 'gallery_event_id');
    }

    /**
     * Relación con la solicitud de bienestar (si este evento está relacionado con una solicitud).
     */
    public function wellnessRequest(): BelongsTo
    {
        return $this->belongsTo(WellnessRequest::class, 'wellness_request_id');
    }

    /**
     * Relación con el usuario que revisó el evento.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get review status translated text.
     */
    public function getReviewStatusTextAttribute(): string
    {
        return match ($this->review_status) {
            'pending' => 'Pendiente',
            'in_review' => 'En revisión',
            'approved' => 'Aprobado',
            'rejected' => 'Rechazado',
            default => ucfirst($this->review_status ?? 'Pendiente'),
        };
    }
}
