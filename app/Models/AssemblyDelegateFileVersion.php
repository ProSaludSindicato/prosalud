<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssemblyDelegateFileVersion extends Model
{
    protected $fillable = [
        'assembly_id',
        'storage_path',
        'disk',
        'original_filename',
        'uploaded_by',
        'row_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function assembly(): BelongsTo
    {
        return $this->belongsTo(Assembly::class, 'assembly_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
