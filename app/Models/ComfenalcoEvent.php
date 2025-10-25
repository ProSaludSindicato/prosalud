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
            return Storage::url($this->banner_image);
        }
        return null;
    }

    /**
     * Get the full banner image path
     */
    public function getBannerImagePathAttribute(): ?string
    {
        if ($this->banner_image) {
            return Storage::path($this->banner_image);
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
            if ($event->banner_image && Storage::exists($event->banner_image)) {
                Storage::delete($event->banner_image);
            }
        });
    }
}
