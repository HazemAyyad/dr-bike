<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

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

    /**
     * Subtasks that truly belong to this legacy task row (excludes orphans after PK reuse).
     */
    public function scopeForLegacyTask(Builder $query, EmployeeTask $task): Builder
    {
        $query->where('employee_task_id', $task->id);

        if (Schema::hasColumn('sub_employee_tasks', 'occurrence_id')) {
            $query->whereNull('occurrence_id');
        }

        $anchor = $task->legacySubtaskAnchorAt();
        if ($anchor !== null && Schema::hasColumn('sub_employee_tasks', 'created_at')) {
            $query->where('sub_employee_tasks.created_at', '>=', $anchor);
        }

        return $query;
    }

    public function employeeTask(){
        return $this->belongsTo(EmployeeTask::class);
    }

    public function completedByEmployee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'completed_by_employee_id');
    }
}
