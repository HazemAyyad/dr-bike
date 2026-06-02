<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FingerprintDeviceUser extends Model
{
    use HasFactory;

    protected $table = 'fingerprint_device_users';

    protected $fillable = [
        'attendance_device_id',
        'device_user_id',
        'name',
        'privilege',
        'card',
        'password',
        'enabled',
        'raw_payload',
        'linked_employee_id',
        'last_synced_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'raw_payload' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function attendanceDevice()
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    public function linkedEmployee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'linked_employee_id');
    }
}

