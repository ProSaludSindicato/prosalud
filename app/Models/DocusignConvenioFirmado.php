<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocusignConvenioFirmado extends Model
{
    use HasFactory;

    protected $table = 'docusign_convenios_firmados';

    protected $fillable = [
        'envelope_id',
        'document_number',
        'recipient_email',
        'recipient_name',
        'recipient_id',
        'recipient_completed_at',
        'envelope_completed_at',
        'storage_path',
        'storage_disk',
        'status',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'recipient_completed_at' => 'datetime',
        'envelope_completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Scope para buscar por número de documento
     */
    public function scopeByDocumentNumber($query, string $documentNumber)
    {
        return $query->where('document_number', $documentNumber);
    }

    /**
     * Scope para buscar por envelope ID
     */
    public function scopeByEnvelopeId($query, string $envelopeId)
    {
        return $query->where('envelope_id', $envelopeId);
    }

    /**
     * Scope para buscar por estado
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para buscar convenios completados
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope para buscar por rango de fechas
     */
    public function scopeByDateRange($query, ?string $fechaDesde = null, ?string $fechaHasta = null)
    {
        if ($fechaDesde) {
            $query->whereDate('envelope_completed_at', '>=', $fechaDesde);
        }

        if ($fechaHasta) {
            $query->whereDate('envelope_completed_at', '<=', $fechaHasta);
        }

        return $query;
    }
}

