<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestForm extends Model
{
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
        'created_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
