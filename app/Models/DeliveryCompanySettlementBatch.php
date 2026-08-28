<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryCompanySettlementBatch extends Model
{
    protected $fillable = [
        'delivery_company_id', 'delivery_company_name', 'amount', 'orders_count',
        'sales_daily_session_id', 'box_id', 'idempotency_key', 'notes', 'created_by',
    ];

    protected $casts = ['amount' => 'float'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DeliveryCompany::class, 'delivery_company_id');
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(SalesOrderSettlement::class, 'delivery_company_settlement_batch_id');
    }
}
