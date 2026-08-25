<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartDeviceSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'smart_device_id',
        'user_id',
        'name',
        'command_code',
        'command_value',
        'scheduled_at',
        'repeat_type',
        'repeat_days',
        'enabled',
        'last_executed_at',
        'next_run_at',
    ];

    protected $casts = [
        'command_value' => 'array',
        'repeat_days' => 'array',
        'scheduled_at' => 'datetime',
        'enabled' => 'boolean',
        'last_executed_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(SmartDevice::class, 'smart_device_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
