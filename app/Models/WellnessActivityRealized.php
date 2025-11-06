<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WellnessActivityRealized extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'wellness_activity_realized';

    protected $fillable = [
        'wellness_request_id',
        'realized_date',
        'real_location',
        'real_attendees_count',
        'realized_description',
        'gift_delivered',
        'listado_asistencia_path',
        'published_to_gallery',
        'gallery_event_id',
    ];

    protected $casts = [
        'realized_date' => 'date',
        'real_attendees_count' => 'integer',
        'published_to_gallery' => 'boolean', // Can be null, true, or false
        'gallery_event_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con la solicitud de bienestar
     */
    public function wellnessRequest(): BelongsTo
    {
        return $this->belongsTo(WellnessRequest::class, 'wellness_request_id');
    }

    /**
     * Relación con las evidencias (imágenes)
     */
    public function evidences(): HasMany
    {
        return $this->hasMany(WellnessActivityEvidence::class, 'activity_realized_id');
    }

    /**
     * Relación con el evento de galería (si está publicado)
     */
    public function galleryEvent(): BelongsTo
    {
        return $this->belongsTo(WellnessEvent::class, 'gallery_event_id');
    }
}

