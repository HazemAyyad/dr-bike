<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

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
        'moved_to_no_date_at',

    ];
    protected $casts = [
        'task_recurrence_time'=>'array',
        'admin_img' => 'array',
        'moved_to_no_date_at' => 'datetime',
    ];

    public function subTasks(){
        $relation = $this->hasMany(SubTask::class);
        if (Schema::hasColumn('sub_tasks', 'sort_order')) {
            return $relation->orderBy('sort_order')->orderBy('id');
        }
        return $relation->orderBy('id');
    }


      //override
    public function setTaskRecurrenceTimeAttribute($value)
    {
        $this->attributes['task_recurrence_time'] = json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    
}
