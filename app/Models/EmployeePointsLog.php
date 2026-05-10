<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeePointsLog extends Model
{
    use HasFactory;

    protected $table = 'employee_points_logs';

    public const OPERATION_ADD = 'add';
    public const OPERATION_DEDUCT = 'deduct';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_ATTENDANCE = 'attendance';
    public const SOURCE_OVERTIME = 'overtime';
    public const SOURCE_ABSENCE = 'absence';
    public const SOURCE_LATENESS = 'lateness';

    protected $fillable = [
        'employee_id',
        'points',
        'operation_type',
        'category',
        'source',
        'reason',
        'notes',
        'points_date',
        'created_by',
    ];

    protected $casts = [
        'points' => 'integer',
        'points_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeInMonth($query, int $year, int $month)
    {
        return $query->whereYear('points_date', $year)->whereMonth('points_date', $month);
    }
}
