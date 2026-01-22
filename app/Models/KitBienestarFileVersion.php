<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Modelo para versionar archivos Excel de kits escolares almacenados en S3
 * 
 * @property int $id
 * @property string $file_name
 * @property string $s3_path
 * @property bool $is_active
 * @property int|null $uploaded_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $uploadedBy
 */
class KitBienestarFileVersion extends Model
{
    protected $table = 'kit_bienestar_file_versions';

    protected $fillable = [
        'file_name',
        's3_path',
        'is_active',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Obtener el usuario que subió el archivo
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Obtener la versión activa actual
     */
    public static function getActiveVersion(): ?self
    {
        return self::where('is_active', true)->latest('created_at')->first();
    }

    /**
     * Marcar esta versión como activa y desactivar las demás
     */
    public function activate(): void
    {
        // Desactivar todas las versiones
        self::where('is_active', true)->update(['is_active' => false]);
        
        // Activar esta versión
        $this->update(['is_active' => true]);
    }
}
