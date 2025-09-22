<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $request_type
 * @property string $document_type
 * @property string $document_number
 * @property string $name
 * @property string $last_name
 * @property string $email
 * @property string $phone_number
 * @property array|null $payload
 * @property string $status
 * @property string $created_at
 * @property string|null $processed_at
 * @property-read string $full_name
 * @property-read string $formatted_created_at
 * @property-read string $formatted_processed_at
 */
class RequestForm extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_type',
        'document_type',
        'document_number',
        'name',
        'last_name',
        'email',
        'phone_number',
        'payload',
        'status',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public $timestamps = false;

    /**
     * Get the full name attribute
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->name . ' ' . $this->last_name);
    }

    /**
     * Get formatted created_at attribute
     */
    public function getFormattedCreatedAtAttribute(): string
    {
        return $this->created_at ? date('d/m/Y H:i:s', strtotime($this->created_at)) : '';
    }

    /**
     * Get formatted processed_at attribute
     */
    public function getFormattedProcessedAtAttribute(): string
    {
        if (!$this->processed_at) {
            return '';
        }

        if (is_string($this->processed_at)) {
            return date('d/m/Y H:i:s', strtotime($this->processed_at));
        }

        return $this->processed_at->format('d/m/Y H:i:s');
    }

    /**
     * Scope for pending requests
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for processed requests
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', 'processed');
    }

    /**
     * Scope for requests by type
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('request_type', $type);
    }

    /**
     * Scope for requests by document number
     */
    public function scopeByDocument(Builder $query, string $documentNumber): Builder
    {
        return $query->where('document_number', $documentNumber);
    }

    /**
     * Scope for requests by email
     */
    public function scopeByEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', $email);
    }

    /**
     * Mark request as processed
     */
    public function markAsProcessed(): bool
    {
        return $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    /**
     * Check if request is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if request is processed
     */
    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    /**
     * Get payload value by key
     */
    public function getPayloadValue(string $key, $default = null)
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * Set payload value
     */
    public function setPayloadValue(string $key, $value): void
    {
        $payload = $this->payload ?? [];
        $payload[$key] = $value;
        $this->payload = $payload;
    }
}
