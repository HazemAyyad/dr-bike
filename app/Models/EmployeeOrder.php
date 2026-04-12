<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeOrder extends Model
{
    use HasFactory;
    protected $table = 'employee_orders';
    protected $fillable = ['employee_id','order','status','type','loan_value','overtime_value','extra_work_hours'];

    public function employee(){
        return $this->belongsTo(EmployeeDetail::class,'employee_id');
    }
}
