<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityIpBlock extends Model
{
    protected $fillable = [
        'ip_address',
        'reason',
        'active',
        'blocked_at',
        'expires_at',
        'created_by_ip',
    ];

    protected $casts = [
        'active' => 'boolean',
        'blocked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isEffective(): bool
    {
        return $this->active && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
