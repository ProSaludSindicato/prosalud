<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ComfenalcoEvent extends Model
{
    public const UPDATED_AT = null;

    public $timestamps = true;
    protected $guarded = [];

    protected $table = 'comfenalco_events';
    protected $primaryKey = 'id';
    
    protected $appends = ['banner_image_url'];

    /**
     * Retrieve the model for route model binding.
     * Validates that the ID is not undefined or invalid.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // Validate that the value is not undefined or empty
        if ($value === null || $value === 'undefined' || $value === '' || $value === 'null') {
            \Illuminate\Support\Facades\Log::warning('ComfenalcoEvent route binding: Invalid ID value', [
                'value' => $value,
                'type' => gettype($value),
            ]);
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }

        // Ensure value is numeric if it should be
        if (!is_numeric($value)) {
            \Illuminate\Support\Facades\Log::warning('ComfenalcoEvent route binding: Non-numeric ID value', [
                'value' => $value,
                'type' => gettype($value),
            ]);
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }

        // Try to find by the primary key (default is 'id')
        $field = $field ?: $this->getRouteKeyName();
        
        try {
            return $this->where($field, $value)->firstOrFail();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Illuminate\Support\Facades\Log::warning('ComfenalcoEvent route binding: Model not found', [
                'value' => $value,
                'field' => $field,
            ]);
            throw $e;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('ComfenalcoEvent route binding: Database error', [
                'value' => $value,
                'field' => $field,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the banner image URL.
     */
    public function getBannerImageUrlAttribute(): ?string
    {
        if ($this->banner_image) {
            $disk = 'prosalud-public';

            return Storage::disk($disk)->url($this->banner_image);
        }

        return null;
    }

    public function getBannerImagePathAttribute(): ?string
    {
        if ($this->banner_image) {
            $disk = 'prosalud-public';

            return Storage::disk($disk)->url($this->banner_image);
        }

        return null;
    }

    /**
     * Delete the banner image file when the model is deleted.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function ($event) {
            try {
                if ($event->banner_image) {
                    $disk = 'prosalud-public';
                    if (Storage::disk($disk)->exists($event->banner_image)) {
                        Storage::disk($disk)->delete($event->banner_image);
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error eliminando banner image en ComfenalcoEvent::boot', [
                    'event_id' => $event->id,
                    'banner_image' => $event->banner_image,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
