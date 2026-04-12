<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpecialTask extends Model
{
    use HasFactory;
    protected $table ='special_tasks';
    protected $fillable = [
        'name',
        'description',
        'notes',
        'points',
        'start_date',
        'end_date',
        'not_shown_for_employee',
        'task_recurrence',
        'task_recurrence_time',
        'status',
        'is_canceled',
        'admin_img',
        'force_employee_to_add_img',
        'audio',
        'parent_id',

    ];
    protected $casts = [
        'task_recurrence_time'=>'array',
        'admin_img' => 'array',
    ];

    public function subTasks(){
        return $this->hasMany(SubTask::class);
    }


      //override
    public function setTaskRecurrenceTimeAttribute($value)
    {
        $this->attributes['task_recurrence_time'] = json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    
}
