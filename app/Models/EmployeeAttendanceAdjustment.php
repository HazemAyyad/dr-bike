<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAttendanceAdjustment extends Model
{
    protected $fillable = [
        'employee_attendance_id',
        'employee_id',
        'work_date',
        'before_values',
        'after_values',
        'edited_by',
        'source',
        'note',
    ];

    protected $casts = [
        'work_date' => 'date',
        'before_values' => 'array',
        'after_values' => 'array',
    ];

    public function attendance()
    {
        return $this->belongsTo(EmployeeAttendance::class, 'employee_attendance_id');
    }
}
