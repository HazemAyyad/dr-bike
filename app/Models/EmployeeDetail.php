<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeDetail extends Model
{
    use HasFactory;
    protected $table = 'employee_details';

    protected $fillable = [
        'user_id',
        'points',
        'hour_work_price',
        'overtime_work_price',
        'number_of_work_hours',
        'start_work_time',
        'end_work_time',
        'job_title',
        'salary',
        'debts',
        'work_time',
        'employee_img',
        'document_img',
        'total_work_hours',
    ];

    protected $casts = [
        'employee_img'=>'array',
        'document_img' => 'array',
    ];


    /**
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(){
        return $this->hasMany(EmployeeTask::class,'employee_id');
    }

    public function boxes(){
        return $this->hasMany(Box::class);
    }

    public function goals(){
        return $this->hasMany(Goal::class);
    }

    public function permissions(){
        return $this->hasMany(EmployeePermission::class,'employee_id');
    }

    public function punishments(){
        return $this->hasMany(Punishment::class,'employee_id');
    }

    public function rewards(){
        return $this->hasMany(Reward::class,'employee_id');
    }

    public function orders(){
        return $this->hasMany(EmployeeOrder::class,'employee_id');
    }

    public function attendances()
    {
            return $this->hasMany(EmployeeAttendance::class, 'employee_id');
        }


}
