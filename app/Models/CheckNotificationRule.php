<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckNotificationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'check_direction',
        'channel',
        'recipient',
        'days',
        'trigger_mode',
        'send_time',
        'message',
        'is_active',
    ];

    protected $casts = [
        'days' => 'integer',
        'is_active' => 'boolean',
    ];
}
