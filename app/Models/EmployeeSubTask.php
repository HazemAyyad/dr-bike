<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSubTask extends Model
{
    use HasFactory;
    protected $table = 'sub_employee_tasks';

    protected $fillable = [
        'name',
        'description',
        'employee_task_id',
        'occurrence_id',
        'is_forced_to_upload_img',
        'proof_media_type',
        'bonus_points',
        'admin_img',
        'status',
        'completed_by_employee_id',
        'employee_img',
        'sort_order',
    ];

        protected $casts = [

        'admin_img'=>'array',
        'employee_img'=>'array',
    ];

    public function employeeTask(){
        return $this->belongsTo(EmployeeTask::class);
    }

    public function completedByEmployee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'completed_by_employee_id');
    }
}
