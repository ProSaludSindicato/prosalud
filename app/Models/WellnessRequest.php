<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $activity_name
 * @property string|null $activity_description
 * @property string $cost_center
 * @property array|null $locations
 * @property string $proposed_date
 * @property string|null $start_time
 * @property string|null $end_time
 * @property int|null $participant_count
 * @property bool $requires_details
 * @property int $requester_id
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $requester
 * @property-read Collection<WellnessRequestDetail> $details
 */
class WellnessRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'activity_name',
        'activity_description',
        'cost_center',
        'locations',
        'proposed_date',
        'start_time',
        'end_time',
        'participant_count',
        'requires_details',
        'requester_id',
        'status',
    ];

    protected $casts = [
        'locations' => 'array',
        'proposed_date' => 'date',
        'participant_count' => 'integer',
        'requires_details' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con el usuario solicitante
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * Relación con los detalles/souvenirs
     */
    public function details(): HasMany
    {
        return $this->hasMany(WellnessRequestDetail::class, 'wellness_request_id');
    }

    /**
     * Get status translated text
     */
    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Pendiente',
            'in_progress' => 'En revisión',
            'resolved' => 'Aprobada',
            'rejected' => 'Rechazada',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get locations as string
     */
    public function getLocationsTextAttribute(): string
    {
        if (empty($this->locations) || !is_array($this->locations)) {
            return 'N/A';
        }
        return implode(', ', $this->locations);
    }
}
