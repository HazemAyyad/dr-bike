<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartDeviceActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'smart_device_id',
        'user_id',
        'action',
        'command_code',
        'command_value',
        'success',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'command_value' => 'array',
        'success' => 'boolean',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(SmartDevice::class, 'smart_device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
