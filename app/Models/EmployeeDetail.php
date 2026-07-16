<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDetail extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'employee_details';

    protected $fillable = [
        'user_id',
        'device_user_id',
        'fingerprint_enabled',
        'points',
        'hour_work_price',
        'overtime_work_price',
        'number_of_work_hours',
        'start_work_time',
        'end_work_time',
        'weekly_days_off',
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
        'weekly_days_off' => 'array',
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

    public function reminders()
    {
        return $this->hasMany(EmployeeReminder::class, 'employee_id');
    }

    public function suggestions()
    {
        return $this->hasMany(EmployeeSuggestion::class, 'employee_id');
    }

    public function supportConversations()
    {
        return $this->hasMany(SupportConversation::class, 'employee_id');
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

    public function attendanceScans()
    {
        return $this->hasMany(EmployeeAttendanceScan::class, 'employee_id');
    }

    public function pointsLogs()
    {
        return $this->hasMany(EmployeePointsLog::class, 'employee_id');
    }


}
