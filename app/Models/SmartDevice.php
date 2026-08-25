<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmartDevice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'smart_home_id',
        'smart_room_id',
        'tuya_device_id',
        'tuya_product_id',
        'tuya_uuid',
        'name',
        'category',
        'product_name',
        'icon',
        'protocol',
        'online',
        'model',
        'manufacturer',
        'raw_metadata',
        'last_status',
        'paired_at',
        'last_seen_at',
    ];

    protected $casts = [
        'online' => 'boolean',
        'raw_metadata' => 'array',
        'last_status' => 'array',
        'paired_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function home(): BelongsTo
    {
        return $this->belongsTo(SmartHome::class, 'smart_home_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(SmartRoom::class, 'smart_room_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(SmartDeviceActivityLog::class);
    }

    public function functions(): HasMany
    {
        return $this->hasMany(SmartDeviceFunction::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(SmartDeviceSchedule::class);
    }
}
