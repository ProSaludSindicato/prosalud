<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property string      $request_form_id
 * @property string|null $old_status
 * @property string      $new_status
 * @property int|null    $changed_by
 * @property string      $created_at
 * @property RequestForm $requestForm
 * @property User|null   $user
 */
class RequestStatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'request_form_id',
        'old_status',
        'new_status',
        'changed_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Request associated with this status change.
     */
    public function requestForm(): BelongsTo
    {
        return $this->belongsTo(RequestForm::class, 'request_form_id', 'id');
    }

    /**
     * User who changed the status.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}


