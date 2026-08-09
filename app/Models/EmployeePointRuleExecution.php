<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeePointRuleExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'employee_id',
        'points_log_id',
        'period_type',
        'period_start',
        'period_end',
        'status',
        'reason',
        'details',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'details' => 'array',
    ];

    public function rule()
    {
        return $this->belongsTo(EmployeePointRule::class, 'rule_id');
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function pointsLog()
    {
        return $this->belongsTo(EmployeePointsLog::class, 'points_log_id');
    }
}
