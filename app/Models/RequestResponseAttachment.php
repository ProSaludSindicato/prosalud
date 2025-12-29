<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $request_response_id
 * @property string $path
 * @property string $original_name
 * @property string $created_at
 * @property RequestResponse $requestResponse
 */
class RequestResponseAttachment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'request_response_id',
        'path',
        'original_name',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Get the request response that this attachment belongs to.
     */
    public function requestResponse(): BelongsTo
    {
        return $this->belongsTo(RequestResponse::class, 'request_response_id');
    }
}
