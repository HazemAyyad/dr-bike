<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeAttendance extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'employee_id',
        'date',
        'arrived_at',
        'left_at',
        'worked_minutes',
        'required_minutes',
        'normal_minutes',
        'overtime_minutes',
    ];

    protected $casts = [
        'date' => 'date',
        'worked_minutes' => 'integer',
        'required_minutes' => 'integer',
        'normal_minutes' => 'integer',
        'overtime_minutes' => 'integer',
    ];



    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }
}
