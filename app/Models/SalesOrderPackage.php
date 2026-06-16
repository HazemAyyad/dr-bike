<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderPackage extends Model
{
    protected $fillable = [
        'sales_order_id',
        'package_index',
        'status',
        'customer_delivery_fee',
        'tracking_number',
    ];

    protected $casts = [
        'customer_delivery_fee' => 'float',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'sales_order_package_id');
    }
}
