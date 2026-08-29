<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPolicyAudit extends Model
{
    protected $fillable = [
        'auditable_type', 'auditable_id', 'action', 'before', 'after',
        'user_id', 'ip_address',
    ];

    protected $casts = ['before' => 'array', 'after' => 'array'];
}
