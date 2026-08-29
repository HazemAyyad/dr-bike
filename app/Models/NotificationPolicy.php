<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPolicy extends Model
{
    protected $fillable = [
        'notification_type', 'is_enabled', 'in_app_enabled', 'push_enabled',
        'priority', 'sound_id', 'fallback_sound_id', 'vibration_enabled',
        'show_foreground_banner', 'show_on_lock_screen', 'quiet_hours_start',
        'quiet_hours_end', 'bypass_quiet_hours', 'cooldown_seconds', 'audience',
        'recipient_user_ids', 'recipient_roles', 'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'in_app_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'vibration_enabled' => 'boolean',
        'show_foreground_banner' => 'boolean',
        'show_on_lock_screen' => 'boolean',
        'bypass_quiet_hours' => 'boolean',
        'recipient_user_ids' => 'array',
        'recipient_roles' => 'array',
    ];

    public function sound(): BelongsTo
    {
        return $this->belongsTo(NotificationSound::class, 'sound_id');
    }

    public function fallbackSound(): BelongsTo
    {
        return $this->belongsTo(NotificationSound::class, 'fallback_sound_id');
    }
}
