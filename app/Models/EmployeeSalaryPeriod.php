<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeSalaryPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id', 'salary_month', 'normal_salary', 'overtime_salary', 'bonuses',
        'gross_entitlement', 'advances_applied', 'total_paid', 'remaining', 'status',
        'calculation_snapshot', 'recognized_expense_id',
    ];

    protected $casts = [
        'salary_month' => 'date:Y-m-d',
        'calculation_snapshot' => 'array',
        'normal_salary' => 'decimal:2',
        'overtime_salary' => 'decimal:2',
        'bonuses' => 'decimal:2',
        'gross_entitlement' => 'decimal:2',
        'advances_applied' => 'decimal:2',
        'total_paid' => 'decimal:2',
        'remaining' => 'decimal:2',
    ];

    public function employee() { return $this->belongsTo(EmployeeDetail::class); }
    public function expense() { return $this->belongsTo(Expense::class, 'recognized_expense_id'); }
    public function payments() { return $this->hasMany(SalaryPaymentItem::class, 'salary_period_id'); }
    public function advanceApplications() { return $this->hasMany(EmployeeAdvanceApplication::class, 'salary_period_id'); }
}
