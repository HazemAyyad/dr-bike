<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotificationReceipt extends Model
{
    protected $fillable = [
        'admin_notification_id', 'user_id', 'seen_at', 'read_at', 'opened_at', 'deleted_at',
    ];

    protected $casts = [
        'seen_at' => 'datetime',
        'read_at' => 'datetime',
        'opened_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
