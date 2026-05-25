<?php

namespace App\Models;

use App\Enums\WellnessHospitalScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WellnessHospitalAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'hospital_scope',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeEnum(): WellnessHospitalScope
    {
        return WellnessHospitalScope::from($this->hospital_scope);
    }

    protected function casts(): array
    {
        return [
            'hospital_scope' => WellnessHospitalScope::class,
        ];
    }
}
