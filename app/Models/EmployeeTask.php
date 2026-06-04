<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class EmployeeTask extends Model
{
    use HasFactory;
    protected $table = 'employee_tasks';

    protected $fillable = [
        'name',
        'description',
        'notes',
        'points',
        'priority',
        'rejection_notes',
        'not_shown_for_employee',
        'employee_id',
        'completed_by_employee_id',
        'start_time',
        'end_time',
        'started_at',
        'submitted_at',
        'reviewed_at',
        'status',
        'admin_img',
        'is_forced_to_upload_img',
        'proof_media_type',
        'requires_admin_review',
        'task_recurrence',
        'task_recurrence_time',
        'employee_img',
        'is_canceled',
        'audio',
        'parent_id',
        'template_id',
        'occurrence_id',
        'reminder_before_minutes',
        'reminder_channel',
    ];

    protected $casts = [
        'task_recurrence_time'=>'array',
        'employee_img'=>'array',
        'admin_img'=>'array',
        'requires_admin_review' => 'boolean',

    ];

    /**
     * Anchor for orphan subtask filtering when employee_tasks lacks created_at.
     */
    public function legacySubtaskAnchorAt(): ?Carbon
    {
        if (Schema::hasColumn($this->getTable(), 'created_at') && $this->created_at) {
            return $this->created_at instanceof Carbon
                ? $this->created_at
                : Carbon::parse($this->created_at);
        }

        if (! empty($this->start_time)) {
            return $this->start_time instanceof Carbon
                ? $this->start_time
                : Carbon::parse($this->start_time);
        }

        return null;
    }

    public function subTasks(){
        $relation = $this->hasMany(EmployeeSubTask::class);

        if (Schema::hasColumn('sub_employee_tasks', 'occurrence_id')) {
            $relation->whereNull('occurrence_id');
        }

        $subTable = $relation->getRelated()->getTable();
        if (
            Schema::hasColumn($this->getTable(), 'created_at')
            && $this->created_at !== null
            && Schema::hasColumn($subTable, 'created_at')
        ) {
            $relation->where("{$subTable}.created_at", '>=', $this->created_at);
        }

        return $relation;
    }

    /**
     * Remove subtasks left on a reused employee_tasks.id (deleted task without cascade).
     */
    public function purgeOrphanSubtasks(): void
    {
        EmployeeSubTask::query()
            ->where('employee_task_id', $this->id)
            ->when(
                Schema::hasColumn('sub_employee_tasks', 'occurrence_id'),
                fn ($q) => $q->whereNull('occurrence_id')
            )
            ->when(
                Schema::hasColumn($this->getTable(), 'created_at') && $this->created_at !== null,
                fn ($q) => $q->where('created_at', '<', $this->created_at)
            )
            ->delete();
    }

    public function employee(){
        return $this->belongsTo(EmployeeDetail::class,'employee_id');
    }

    public function completedByEmployee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'completed_by_employee_id');
    }

    public function assignees()
    {
        return $this->belongsToMany(
            EmployeeDetail::class,
            'employee_task_assignees',
            'employee_task_id',
            'employee_id'
        )->withTimestamps();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmployeeTaskTemplate::class, 'template_id');
    }

    //override
    public function setTaskRecurrenceTimeAttribute($value)
    {
        $this->attributes['task_recurrence_time'] = json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    
}
