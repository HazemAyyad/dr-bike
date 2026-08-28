<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderSettlement extends Model
{
    protected $fillable = [
        'sales_order_id', 'sales_daily_session_id', 'box_id', 'source', 'amount',
        'customer_debt_before', 'customer_debt_after', 'carrier_receivable_before',
        'carrier_receivable_after', 'idempotency_key', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'customer_debt_before' => 'float',
        'customer_debt_after' => 'float',
        'carrier_receivable_before' => 'float',
        'carrier_receivable_after' => 'float',
    ];

    public function order(): BelongsTo { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function session(): BelongsTo { return $this->belongsTo(SalesDailySession::class, 'sales_daily_session_id'); }
    public function box(): BelongsTo { return $this->belongsTo(Box::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
