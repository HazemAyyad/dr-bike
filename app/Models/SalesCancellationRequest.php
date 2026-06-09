<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCancellationRequest extends Model
{
    protected $fillable = [
        'sale_type',
        'sale_id',
        'session_id',
        'requested_by_user_id',
        'requested_at',
        'reason',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'reversal_box_id',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(SalesDailySession::class, 'session_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reversalBox(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'reversal_box_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
