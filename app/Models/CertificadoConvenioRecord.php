<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CertificadoConvenioRecord extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'certificado_convenio_records';

    protected $fillable = [
        'document_number',
        'consecutivo',
        'storage_path',
        'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    /**
     * Scope para buscar por documento y consecutivo
     */
    public function scopeByDocumentoAndConsecutivo($query, string $documento, string $consecutivo)
    {
        return $query->where('document_number', $documento)
            ->where('consecutivo', $consecutivo);
    }

    /**
     * Scope para buscar por documento
     */
    public function scopeByDocumento($query, string $documento)
    {
        return $query->where('document_number', $documento);
    }

    /**
     * Scope para buscar por rango de fechas
     */
    public function scopeByFechaRango($query, ?string $fechaDesde = null, ?string $fechaHasta = null)
    {
        if ($fechaDesde) {
            $query->whereDate('generated_at', '>=', $fechaDesde);
        }
        
        if ($fechaHasta) {
            $query->whereDate('generated_at', '<=', $fechaHasta);
        }
        
        return $query;
    }

    /**
     * Scope para buscar por consecutivo (búsqueda parcial)
     */
    public function scopeByConsecutivo($query, string $consecutivo)
    {
        return $query->where('consecutivo', 'like', "%{$consecutivo}%");
    }
}
