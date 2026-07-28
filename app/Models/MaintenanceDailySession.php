<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceDailySession extends Model
{
    protected $fillable = [
        'user_id',
        'employee_id',
        'business_date',
        'status',
        'box_id',
        'opening_balance',
        'closing_balance',
        'opened_at',
        'closed_at',
        'opened_by_user_id',
        'closing_requested_at',
        'closing_requested_by_user_id',
        'closing_request_note',
        'closed_by_user_id',
        'notes',
    ];

    protected $casts = [
        'business_date' => 'date',
        'opening_balance' => 'float',
        'closing_balance' => 'float',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'closing_requested_at' => 'datetime',
    ];

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function closingRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closing_requested_by_user_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceDailyBoxLog::class, 'session_id');
    }

    public function closingRequests(): HasMany
    {
        return $this->hasMany(MaintenanceDailyClosingRequest::class, 'session_id');
    }

    public function latestClosingRequest()
    {
        return $this->hasOne(MaintenanceDailyClosingRequest::class, 'session_id')->latestOfMany();
    }

    public function isOpen(): bool
    {
        return $this->status === config('maintenance_daily.session_status.open', 'open');
    }

    public function isClosingRequested(): bool
    {
        return $this->status === config('maintenance_daily.session_status.closing_requested', 'closing_requested');
    }
}
