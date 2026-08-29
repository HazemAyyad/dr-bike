<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = [
        'notification_type', 'locale', 'title_template', 'body_template',
        'lock_screen_template', 'is_active', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean'];
}
