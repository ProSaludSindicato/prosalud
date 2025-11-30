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
}
