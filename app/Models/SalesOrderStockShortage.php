<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderStockShortage extends Model
{
    protected $fillable = [
        'sales_order_id', 'sales_order_item_id', 'product_id', 'size_color_id',
        'requested_qty', 'available_qty', 'shortage_qty', 'status', 'last_notified_at',
        'resolved_by', 'resolved_at',
    ];

    protected $casts = ['last_notified_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function item(): BelongsTo { return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
