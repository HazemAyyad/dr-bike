<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceDailySession extends Model
{
    protected $fillable = [
        'business_date',
        'status',
        'box_id',
        'opening_balance',
        'closing_balance',
        'opened_at',
        'closed_at',
        'opened_by_user_id',
        'closed_by_user_id',
        'notes',
    ];

    protected $casts = [
        'business_date' => 'date',
        'opening_balance' => 'float',
        'closing_balance' => 'float',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceDailyBoxLog::class, 'session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === config('maintenance_daily.session_status.open', 'open');
    }
}
