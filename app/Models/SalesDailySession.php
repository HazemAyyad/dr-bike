<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesDailySession extends Model
{
    protected $fillable = [
        'user_id',
        'employee_id',
        'business_date',
        'status',
        'opening_balances',
        'opened_at',
        'closed_at',
        'opened_by_user_id',
        'closed_by_user_id',
        'notes',
    ];

    protected $casts = [
        'business_date' => 'date',
        'opening_balances' => 'array',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeDetail::class, 'employee_id');
    }

    public function closingRequests(): HasMany
    {
        return $this->hasMany(SalesDailyClosingRequest::class, 'session_id');
    }

    public function reopenRequests(): HasMany
    {
        return $this->hasMany(SalesDailyReopenRequest::class, 'session_id');
    }

    public function latestClosingRequest(): HasOne
    {
        return $this->hasOne(SalesDailyClosingRequest::class, 'session_id')->latestOfMany();
    }

    public function instantSales(): HasMany
    {
        return $this->hasMany(InstantSale::class, 'sales_daily_session_id');
    }

    public function profitSales(): HasMany
    {
        return $this->hasMany(ProfitSale::class, 'sales_daily_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === config('sales_daily.session_status.open');
    }

    public function isClosingRequested(): bool
    {
        return $this->status === config('sales_daily.session_status.closing_requested');
    }

    public function isClosed(): bool
    {
        return $this->status === config('sales_daily.session_status.closed');
    }

    public function allowsSales(): bool
    {
        return $this->isOpen();
    }
}
