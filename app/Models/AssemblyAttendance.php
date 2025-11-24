<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AssemblyAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'assembly_id',
        'document_number',
        'full_name',
        'issue_date_normalized',
        'signature_path',
        'ip_address',
        'user_agent',
        'authenticated_at',
    ];

    protected $casts = [
        'issue_date_normalized' => 'date',
        'authenticated_at' => 'datetime',
    ];

    protected $appends = [
        'signature_url',
    ];

    public function getSignatureUrlAttribute(): ?string
    {
        if (!$this->signature_path) {
            return null;
        }

        try {
            $disk = Storage::disk('prosalud-private');

            if (method_exists($disk, 'temporaryUrl')) {
                return $disk->temporaryUrl(
                    $this->signature_path,
                    now()->addMinutes(config('filesystems.assembly_signature_ttl', 10))
                );
            }

            if (method_exists($disk, 'url')) {
                return $disk->url($this->signature_path);
            }
        } catch (\Throwable $e) {
            Log::warning('No fue posible generar URL temporal de firma de asamblea', [
                'attendance_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    public function assembly(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Assembly::class, 'assembly_id');
    }
}

