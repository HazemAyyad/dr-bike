<?php

namespace App\Models\Store;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreSalesOrder extends Model
{
    protected $table = 'sales_orders';

    protected $guarded = [];

    public function details(): HasMany
    {
        return $this->hasMany(StoreSalesOrderItem::class, 'sales_order_id');
    }
}
