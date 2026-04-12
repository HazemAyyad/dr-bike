<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeSubTask extends Model
{
    use HasFactory;
    protected $table = 'sub_employee_tasks';

    protected $fillable = [
        'name',
        'description',
        'employee_task_id',
        'is_forced_to_upload_img',
        'admin_img',
        'status',
        'employee_img',

    ];

        protected $casts = [

        'admin_img'=>'array',
        'employee_img'=>'array',
    ];

    public function employeeTask(){
        return $this->belongsTo(EmployeeTask::class);
    }
}
