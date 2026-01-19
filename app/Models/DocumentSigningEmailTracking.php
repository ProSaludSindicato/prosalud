<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSigningEmailTracking extends Model
{
    use HasFactory;

    protected $table = 'document_signing_email_trackings';

    protected $fillable = [
        'envelope_id',
        'document_number',
        'recipient_email',
        'recipient_name',
        'provider',
        'email_status',
        'sent_at',
        'delivered_at',
        'opened_at',
        'signed_at',
        'error_message',
        'open_count',
        'metadata',
        'parent_tracking_id',
        'resend_count',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'signed_at' => 'datetime',
        'metadata' => 'array',
        'open_count' => 'integer',
        'resend_count' => 'integer',
    ];

    /**
     * Relación con el tracking padre (si es un reenvío)
     */
    public function parentTracking(): BelongsTo
    {
        return $this->belongsTo(DocumentSigningEmailTracking::class, 'parent_tracking_id');
    }

    /**
     * Relación con los reenvíos
     */
    public function resends()
    {
        return $this->hasMany(DocumentSigningEmailTracking::class, 'parent_tracking_id');
    }

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
     * Scope para buscar por estado de correo
     */
    public function scopeByEmailStatus($query, string $status)
    {
        return $query->where('email_status', $status);
    }

    /**
     * Scope para buscar por proveedor
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope para buscar por rango de fechas
     */
    public function scopeByDateRange($query, ?string $fechaDesde = null, ?string $fechaHasta = null)
    {
        if ($fechaDesde) {
            $query->whereDate('created_at', '>=', $fechaDesde);
        }

        if ($fechaHasta) {
            $query->whereDate('created_at', '<=', $fechaHasta);
        }

        return $query;
    }

    /**
     * Marcar como enviado
     */
    public function markAsSent(): void
    {
        $this->update([
            'email_status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Marcar como entregado
     */
    public function markAsDelivered(): void
    {
        $this->update([
            'email_status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }

    /**
     * Marcar como abierto
     */
    public function markAsOpened(?array $metadata = null): void
    {
        $this->increment('open_count');
        
        if (!$this->opened_at) {
            $this->update([
                'email_status' => 'opened',
                'opened_at' => now(),
            ]);
        }

        if ($metadata) {
            $currentMetadata = $this->metadata ?? [];
            $currentMetadata['opens'][] = [
                'timestamp' => now()->toISOString(),
                'metadata' => $metadata,
            ];
            $this->update(['metadata' => $currentMetadata]);
        }
    }

    /**
     * Marcar como fallido
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'email_status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Marcar como rebotado
     */
    public function markAsBounced(string $errorMessage): void
    {
        $this->update([
            'email_status' => 'bounced',
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Marcar como firmado
     */
    public function markAsSigned(): void
    {
        $this->update([
            'signed_at' => now(),
        ]);
    }
}
