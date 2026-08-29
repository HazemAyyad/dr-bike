<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDeliveryAttempt extends Model
{
    protected $fillable = [
        'admin_notification_id', 'admin_device_token_id', 'requested_sound_id',
        'resolved_sound_id', 'status', 'channel_id', 'used_fallback',
        'failure_reason', 'sent_at',
    ];

    protected $casts = ['used_fallback' => 'boolean', 'sent_at' => 'datetime'];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(AdminNotification::class, 'admin_notification_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AdminDeviceToken::class, 'admin_device_token_id');
    }
}
