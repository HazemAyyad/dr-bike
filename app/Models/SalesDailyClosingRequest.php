<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDailyClosingRequest extends Model
{
    protected $fillable = [
        'session_id',
        'requested_by_user_id',
        'requested_at',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'instant_sales_count',
        'profit_sales_count',
        'cash_counts',
        'late_close_reason',
        'transfers',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'cash_counts' => 'array',
        'transfers' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(SalesDailySession::class, 'session_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
