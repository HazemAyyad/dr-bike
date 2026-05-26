<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeReminderHistory extends Model
{
    protected $fillable = [
        'reminder_id',
        'occurrence_id',
        'employee_id',
        'actor_id',
        'event',
        'title',
        'body',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(EmployeeReminder::class, 'reminder_id');
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EmployeeReminderOccurrence::class, 'occurrence_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
