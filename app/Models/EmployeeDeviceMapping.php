<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeDeviceMapping extends Model
{
    use HasFactory;

    protected $table = 'employee_device_mappings';

    protected $fillable = [
        'employee_id',
        'attendance_device_id',
        'device_user_id',
        'device_user_name',
        'device_card_number',
        'enabled',
        'last_seen_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function attendanceDevice()
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }
}

