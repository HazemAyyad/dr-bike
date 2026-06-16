<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    protected $fillable = [
        'sales_order_id',
        'sales_order_package_id',
        'product_id',
        'size_id',
        'size_color_id',
        'product_name',
        'quantity',
        'reserved_qty',
        'dispatched_qty',
        'delivered_qty',
        'returned_qty',
        'unit_price',
        'line_total',
        'is_hidden',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'line_total' => 'float',
        'is_hidden' => 'boolean',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SalesOrderPackage::class, 'sales_order_package_id');
    }
}
