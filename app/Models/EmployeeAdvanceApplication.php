<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeAdvanceApplication extends Model
{
    use HasFactory;

    protected $fillable = ['employee_order_id', 'salary_period_id', 'amount'];
    protected $casts = ['amount' => 'decimal:2'];

    public function order() { return $this->belongsTo(EmployeeOrder::class, 'employee_order_id'); }
    public function salaryPeriod() { return $this->belongsTo(EmployeeSalaryPeriod::class, 'salary_period_id'); }
}
