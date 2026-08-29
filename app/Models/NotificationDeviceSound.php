<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDeviceSound extends Model
{
    protected $fillable = [
        'admin_device_token_id', 'notification_sound_id', 'sound_version',
        'status', 'channel_id', 'last_error', 'synced_at',
    ];

    protected $casts = ['synced_at' => 'datetime'];
}
