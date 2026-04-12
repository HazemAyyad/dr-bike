<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeTask extends Model
{
    use HasFactory;
    protected $table = 'employee_tasks';

    protected $fillable = [
        'name',
        'description',
        'notes',
        'points',
        'not_shown_for_employee',
        'employee_id',
        'start_time',
        'end_time',
        'status',
        'admin_img',
        'is_forced_to_upload_img',
        'task_recurrence',
        'task_recurrence_time',
        'employee_img',
        'is_canceled',
        'audio',
        'parent_id',
    ];

    protected $casts = [
        'task_recurrence_time'=>'array',
        'employee_img'=>'array',
        'admin_img'=>'array',

    ];

    public function subTasks(){
        return $this->hasMany(EmployeeSubTask::class);
    }

    public function employee(){
        return $this->belongsTo(EmployeeDetail::class,'employee_id');
    }

    //override
    public function setTaskRecurrenceTimeAttribute($value)
    {
        $this->attributes['task_recurrence_time'] = json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    
}
