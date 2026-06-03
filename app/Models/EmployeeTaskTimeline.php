<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeTaskTimeline extends Model
{
    public $timestamps = false;

    protected $table = 'employee_task_timeline';

    protected $fillable = [
        'employee_task_id',
        'occurrence_id',
        'event_type',
        'actor_id',
        'actor_type',
        'notes',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public const EVENT_CREATED = 'task_created';
    public const EVENT_STARTED = 'task_started';
    public const EVENT_PROOF_UPLOADED = 'proof_uploaded';
    public const EVENT_SUBMITTED = 'task_submitted';
    public const EVENT_APPROVED = 'task_approved';
    public const EVENT_REJECTED = 'task_rejected';
    public const EVENT_REOPENED = 'task_reopened';
    public const EVENT_OVERDUE = 'task_overdue';
    public const EVENT_CANCELED = 'task_canceled';
    public const EVENT_SUBTASK_COMPLETED = 'subtask_completed';
}
