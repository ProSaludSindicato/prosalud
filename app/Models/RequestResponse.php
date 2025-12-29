<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * @property int         $id
 * @property string      $request_form_id
 * @property int|null    $responded_by
 * @property string      $status
 * @property string      $email_subject
 * @property string      $email_body
 * @property string      $created_at
 * @property RequestForm $requestForm
 * @property User|null   $responder
 */
class RequestResponse extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'request_form_id',
        'responded_by',
        'status',
        'email_subject',
        'email_body',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Get the request form that this response belongs to.
     */
    public function requestForm(): BelongsTo
    {
        return $this->belongsTo(RequestForm::class, 'request_form_id', 'id');
    }

    /**
     * Get the user who responded to this request.
     */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /**
     * Get all attachments for this response.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(RequestResponseAttachment::class, 'request_response_id')
                    ->orderBy('created_at', 'asc');
    }
}
