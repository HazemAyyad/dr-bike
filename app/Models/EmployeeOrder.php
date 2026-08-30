<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeOrder extends Model
{
    use HasFactory;
    protected $table = 'employee_orders';
    protected $fillable = [
        'employee_id',
        'order',
        'status',
        'type',
        'loan_value',
        'overtime_value',
        'extra_work_hours',
        'rejection_reason',
        'approved_box_id',
        'box_log_id',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'edited_after_approval_by',
        'edited_after_approval_at',
        'previous_loan_value',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'edited_after_approval_at' => 'datetime',
        'previous_loan_value' => 'float',
    ];

    public function employee(){
        return $this->belongsTo(EmployeeDetail::class,'employee_id');
    }

    public function approvedBox()
    {
        return $this->belongsTo(Box::class, 'approved_box_id');
    }

    public function boxLog()
    {
        return $this->belongsTo(BoxLog::class, 'box_log_id');
    }

    public function salaryApplications()
    {
        return $this->hasMany(EmployeeAdvanceApplication::class, 'employee_order_id');
    }
}
