<?php

namespace App\Models\Store;

use App\Models\SalesOrderDelivery;
use App\Models\SalesOrderShiplyEvent;
use App\Models\SalesOrderStatusLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreSalesOrder extends Model
{
    protected $table = 'sales_orders';

    protected $guarded = [];

    public function details(): HasMany
    {
        return $this->hasMany(StoreSalesOrderItem::class, 'sales_order_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(SalesOrderStatusLog::class, 'sales_order_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SalesOrderDelivery::class, 'sales_order_id');
    }

    public function latestHandover(): HasOne
    {
        return $this->hasOne(SalesOrderDelivery::class, 'sales_order_id')->latestOfMany();
    }

    public function shiplyEvents(): HasMany
    {
        return $this->hasMany(SalesOrderShiplyEvent::class, 'sales_order_id');
    }
}
