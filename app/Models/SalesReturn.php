<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    protected $fillable = [
        'serial_number',
        'sales_order_id',
        'return_type',
        'instant_sale_id',
        'customer_id',
        'seller_id',
        'status',
        'total_amount',
        'currency',
        'cash_refund_amount',
        'credit_amount',
        'refund_box_id',
        'debt_transaction_id',
        'sales_daily_session_id',
        'note',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'replaces_sales_return_id',
        'replacement_sales_return_id',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'cash_refund_amount' => 'float',
        'credit_amount' => 'float',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function instantSale(): BelongsTo
    {
        return $this->belongsTo(InstantSale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function refundBox(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'refund_box_id');
    }

    public function debtTransaction(): BelongsTo
    {
        return $this->belongsTo(DebtTransaction::class);
    }

    public function salesDailySession(): BelongsTo
    {
        return $this->belongsTo(SalesDailySession::class);
    }
}
