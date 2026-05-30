<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckNotificationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'check_type',
        'check_id',
        'event_type',
        'phone',
        'message',
        'status',
        'response',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
