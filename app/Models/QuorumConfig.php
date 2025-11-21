<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuorumConfig extends Model
{
    public $timestamps = false;
    const UPDATED_AT = 'updated_at';

    protected $table = 'quorum_config';

    protected $fillable = [
        'total_delegates',
        'present_delegates',
        'required_percentage',
        'verified',
        'assembly_id',
    ];

    protected $casts = [
        'total_delegates' => 'integer',
        'present_delegates' => 'integer',
        'required_percentage' => 'integer',
        'verified' => 'boolean',
        'updated_at' => 'datetime',
    ];

    public function isQuorumMet(): bool
    {
        return $this->present_delegates >= ($this->total_delegates * $this->required_percentage / 100);
    }

    public function verify(): void
    {
        $this->verified = $this->isQuorumMet();
        $this->save();
    }

    public static function getCurrent(): ?self
    {
        return self::orderBy('updated_at', 'desc')->first();
    }
}
