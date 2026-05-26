<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeReminderOccurrence extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_SNOOZED = 'snoozed';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'reminder_id',
        'employee_id',
        'scheduled_at',
        'notified_at',
        'completed_at',
        'snoozed_until',
        'status',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'notified_at' => 'datetime',
        'completed_at' => 'datetime',
        'snoozed_until' => 'datetime',
    ];

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(EmployeeReminder::class, 'reminder_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }
}
