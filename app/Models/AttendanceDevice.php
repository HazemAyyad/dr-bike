<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceDevice extends Model
{
    use HasFactory;

    protected $table = 'attendance_devices';

    protected $fillable = [
        'name',
        'model',
        'serial_number',
        'ip_address',
        'port',
        'communication_password',
        'is_active',
        'sync_mode',
        'last_seen_at',
        'last_sync_at',
        'last_sync_status',
        'last_sync_error',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function fingerprintDeviceUsers()
    {
        return $this->hasMany(FingerprintDeviceUser::class, 'attendance_device_id');
    }

    public function fingerprintRawLogs()
    {
        return $this->hasMany(FingerprintRawLog::class, 'attendance_device_id');
    }
}

