<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeReminder extends Model
{
    use SoftDeletes;

    public const REPEAT_ONCE = 'once';
    public const REPEAT_DAILY = 'daily';
    public const REPEAT_WEEKLY = 'weekly';
    public const REPEAT_MONTHLY = 'monthly';

    public const REPEAT_TYPES = [
        self::REPEAT_ONCE,
        self::REPEAT_DAILY,
        self::REPEAT_WEEKLY,
        self::REPEAT_MONTHLY,
    ];

    protected $fillable = [
        'employee_id',
        'created_by',
        'title',
        'description',
        'scheduled_at',
        'repeat_type',
        'is_active',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(EmployeeReminderOccurrence::class, 'reminder_id');
    }
}
