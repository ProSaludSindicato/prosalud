<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ComfenalcoEvent extends Model
{
    protected $guarded = [];

    public $timestamps = true;
    const UPDATED_AT = null;

    protected $appends = ['banner_image_url'];

    /**
     * Get the banner image URL
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
     * Delete the banner image file when the model is deleted
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
