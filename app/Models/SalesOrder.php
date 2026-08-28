<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrder extends Model
{
    protected $fillable = [
        'serial_number',
        'customer_id',
        'partner_type',
        'partner_id',
        'customer_name',
        'customer_phone',
        'customer_address',
        'partner_address_id',
        'address_snapshot',
        'city_id',
        'shiply_city_id',
        'shiply_village_id',
        'shiply_city_name',
        'shiply_village_name',
        'status',
        'stuck_previous_status',
        'stuck_type',
        'stuck_reason',
        'stuck_assigned_to',
        'stuck_follow_up_at',
        'stuck_resolved_at',
        'parent_order_id',
        'root_order_id',
        'payment_type',
        'payment_box_id',
        'payment_amount',
        'delivery_company_id',
        'delivery_company_name',
        'customer_delivery_fee',
        'price_includes_delivery',
        'shiply_quoted_delivery_fee',
        'carrier_delivery_cost',
        'subtotal',
        'discount',
        'calculated_total',
        'total',
        'customer_debt_balance',
        'carrier_receivable_balance',
        'debt_id',
        'instant_sale_id',
        'hidden_until',
        'postponed_until',
        'postpone_reason',
        'is_debt_collection',
        'delivery_settled_at',
        'delivery_settled_amount',
        'delivery_settled_box_id',
        'stock_deducted_at',
        'financial_posted_at',
        'sales_daily_session_id',
        'archived_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'customer_delivery_fee' => 'float',
        'price_includes_delivery' => 'boolean',
        'shiply_quoted_delivery_fee' => 'float',
        'carrier_delivery_cost' => 'float',
        'subtotal' => 'float',
        'discount' => 'float',
        'calculated_total' => 'float',
        'total' => 'float',
        'customer_debt_balance' => 'float',
        'carrier_receivable_balance' => 'float',
        'address_snapshot' => 'array',
        'payment_amount' => 'float',
        'delivery_settled_amount' => 'float',
        'is_debt_collection' => 'boolean',
        'hidden_until' => 'datetime',
        'postponed_until' => 'datetime',
        'delivery_settled_at' => 'datetime',
        'stock_deducted_at' => 'datetime',
        'financial_posted_at' => 'datetime',
        'archived_at' => 'datetime',
        'stuck_follow_up_at' => 'datetime',
        'stuck_resolved_at' => 'datetime',
    ];

    public function statusEnum(): SalesOrderStatus
    {
        return SalesOrderStatus::normalize($this->status);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_order_id');
    }

    public function rootOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_order_id');
    }

    public function childOrders(): HasMany
    {
        return $this->hasMany(self::class, 'parent_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(SalesOrderPackage::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(SalesOrderStatusLog::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(SalesOrderMedia::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SalesOrderDelivery::class);
    }

    public function latestDelivery(): HasOne
    {
        return $this->hasOne(SalesOrderDelivery::class)->latestOfMany();
    }

    public function shiplyEvents(): HasMany
    {
        return $this->hasMany(SalesOrderShiplyEvent::class);
    }

    public function deliveryCompany(): BelongsTo
    {
        return $this->belongsTo(DeliveryCompany::class);
    }

    public function instantSale(): BelongsTo
    {
        return $this->belongsTo(InstantSale::class);
    }

    public function salesDailySession(): BelongsTo
    {
        return $this->belongsTo(SalesDailySession::class, 'sales_daily_session_id');
    }

    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function partnerAddress(): BelongsTo
    {
        return $this->belongsTo(PartnerAddress::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(SalesOrderSettlement::class);
    }

    public function stockShortages(): HasMany
    {
        return $this->hasMany(SalesOrderStockShortage::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stuckAssignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stuck_assigned_to');
    }
}
