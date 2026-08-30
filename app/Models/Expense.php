<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;
    protected $table = 'expenses';
      protected $fillable = [
        'name',
        'price',
        'payment_method',
        'notes',
        'invoice_img',
        'media','box_id','created_by_user_id','employee_id','salary_period_id','expense_type','expense_date','destruction_id'
    ];

    protected $casts = [
        'media' => 'array', // Cast JSON column to array
        'invoice_img' =>'array',
        'expense_date' => 'date',
    ];

    public function box(){
        return $this->belongsTo(Box::class);
    }

    public function salaryPeriod()
    {
        return $this->belongsTo(EmployeeSalaryPeriod::class, 'salary_period_id');
    }
}
