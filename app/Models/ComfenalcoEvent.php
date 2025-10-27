<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ComfenalcoEvent extends Model
{
    protected $guarded = [];

    /**
     * Get the banner image URL
     */
    public function getBannerImageUrlAttribute(): ?string
    {
        if ($this->banner_image) {
            $disk = 'public';
            return Storage::disk($disk)->url($this->banner_image);
        }
        return null;
    }

    /**
     * Get the full banner image path
     */
    public function getBannerImagePathAttribute(): ?string
    {
        if ($this->banner_image) {
            $disk = 'public';
            return Storage::disk($disk)->path($this->banner_image);
        }
        return null;
    }

    /**
     * Delete the banner image file when the model is deleted
     */
    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function ($event) {
            try {
                if ($event->banner_image) {
                    $disk = 'public';
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
