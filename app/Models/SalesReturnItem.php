<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnItem extends Model
{
    protected $fillable = [
        'sales_return_id',
        'sales_order_item_id',
        'instant_sale_id',
        'product_id',
        'size_id',
        'size_color_id',
        'product_name',
        'quantity',
        'unit_price',
        'original_unit_price',
        'inventory_unit_cost',
        'inventory_total_cost',
        'line_total',
        'price_override_reason',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'original_unit_price' => 'float',
        'inventory_unit_cost' => 'float',
        'inventory_total_cost' => 'float',
        'line_total' => 'float',
    ];

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function instantSale(): BelongsTo
    {
        return $this->belongsTo(InstantSale::class);
    }
}
