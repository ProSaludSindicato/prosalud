<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HospitalRequest extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'hospital_requests';

    protected $fillable = [
        'hospital_id',
        'hospital_name',
        'requested_by',
        'status',
        'observations',
    ];

    /**
     * Get all items for this request
     */
    public function items(): HasMany
    {
        return $this->hasMany(HospitalRequestItem::class, 'hospital_request_id');
    }

    /**
     * Get all timeline entries for this request
     */
    public function timeline(): HasMany
    {
        return $this->hasMany(HospitalRequestTimeline::class, 'hospital_request_id')
            ->orderBy('timestamp', 'asc');
    }

    /**
     * Valid status transitions
     */
    public static function getValidTransitions(): array
    {
        return [
            'pending' => ['approved', 'rejected'],
            'approved' => ['preparing', 'rejected'],
            'preparing' => ['shipped'],
            'shipped' => ['delivered'],
            'delivered' => [],
            'rejected' => [],
        ];
    }

    /**
     * Check if transition is valid
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $validTransitions = self::getValidTransitions();

        return in_array($newStatus, $validTransitions[$this->status] ?? []);
    }

    /**
     * Update status and create timeline entry
     */
    public function updateStatus(string $newStatus, ?string $actor = null, ?string $description = null): bool
    {
        if (!$this->canTransitionTo($newStatus)) {
            return false;
        }

        $this->status = $newStatus;
        $this->save();

        // Create timeline entry
        $this->timeline()->create([
            'status' => $newStatus,
            'timestamp' => now(),
            'actor' => $actor,
            'description' => $description,
        ]);

        return true;
    }
}
