<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property string      $request_form_id
 * @property string      $status
 * @property string      $email_subject
 * @property string      $email_body
 * @property string      $created_at
 * @property RequestForm $requestForm
 */
class RequestResponse extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'request_form_id',
        'status',
        'email_subject',
        'email_body',
        'created_at',
    ];

    /**
     * Get the request form that this response belongs to.
     */
    public function requestForm(): BelongsTo
    {
        return $this->belongsTo(RequestForm::class, 'request_form_id', 'id');
    }
}
