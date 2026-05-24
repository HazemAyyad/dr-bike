<?php

namespace App\Models;

use App\Enums\EmployeeTaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeTaskOccurrence extends Model
{
    protected $fillable = [
        'template_id',
        'employee_id',
        'completed_by_employee_id',
        'legacy_task_id',
        'name',
        'description',
        'notes',
        'points',
        'priority',
        'status',
        'is_canceled',
        'start_time',
        'end_time',
        'scheduled_date',
        'employee_img',
        'admin_img',
        'audio',
        'is_forced_to_upload_img',
        'requires_admin_review',
        'not_shown_for_employee',
        'rejection_notes',
        'employee_notes',
        'started_at',
        'submitted_at',
        'reviewed_at',
        'completed_at',
    ];

    protected $casts = [
        'employee_img' => 'array',
        'admin_img' => 'array',
        'is_forced_to_upload_img' => 'boolean',
        'requires_admin_review' => 'boolean',
        'not_shown_for_employee' => 'boolean',
        'is_canceled' => 'boolean',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'scheduled_date' => 'date',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmployeeTaskTemplate::class, 'template_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function completedByEmployee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'completed_by_employee_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(EmployeeTaskOccurrenceSubtask::class, 'occurrence_id')->orderBy('sort_order');
    }

    public function legacyTask(): BelongsTo
    {
        return $this->belongsTo(EmployeeTask::class, 'legacy_task_id');
    }

    public function normalizedStatus(): EmployeeTaskStatus
    {
        return EmployeeTaskStatus::normalize($this->status);
    }
}
