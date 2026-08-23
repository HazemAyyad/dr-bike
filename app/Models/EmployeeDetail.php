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
        'is_suspended',
        'suspended_at',
        'suspended_by',
        'suspension_reason',
        'device_user_id',
        'fingerprint_enabled',
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
        'wifi_ssid',
        'wifi_connected',
        'network_connected',
        'wifi_connection_type',
        'wifi_status_updated_at',
    ];

    protected $casts = [
        'employee_img'=>'array',
        'document_img' => 'array',
        'weekly_days_off' => 'array',
        'is_suspended' => 'boolean',
        'suspended_at' => 'datetime',
        'wifi_connected' => 'boolean',
        'network_connected' => 'boolean',
        'wifi_status_updated_at' => 'datetime',
    ];


    /**
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function suspender()
    {
        return $this->belongsTo(User::class, 'suspended_by');
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

    public function visibleBoxes()
    {
        return $this->belongsToMany(Box::class, 'employee_visible_boxes', 'employee_id', 'box_id')
            ->withTimestamps();
    }

    public function goals(){
        return $this->hasMany(Goal::class);
    }

    public function goalShares()
    {
        return $this->hasMany(GoalEmployeeShare::class, 'employee_id');
    }

    public function sharedGoals()
    {
        return $this->belongsToMany(Goal::class, 'goal_employee_shares', 'employee_id', 'goal_id')
            ->withTimestamps();
    }

    public function permissions(){
        return $this->hasMany(EmployeePermission::class,'employee_id');
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

    public function activityLogs()
    {
        return $this->hasMany(EmployeeActivityLog::class, 'employee_id');
    }


}
