<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeAttendance extends Model
{
    use HasFactory;
    
    protected $fillable = ['employee_id', 'date', 'arrived_at', 'left_at', 'worked_minutes'];



    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }
}
