<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssemblyAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_number',
        'full_name',
        'issue_date_normalized',
        'ip_address',
        'user_agent',
        'authenticated_at',
    ];

    protected $casts = [
        'issue_date_normalized' => 'date',
        'authenticated_at' => 'datetime',
    ];
}

