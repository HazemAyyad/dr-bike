<?php

namespace App\Models\Store;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSalesOrderItem extends Model
{
    protected $table = 'sales_order_items';

    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreSalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'product_id');
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(StoreSize::class, 'size_id');
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(StoreSizeColor::class, 'size_color_id');
    }
}
