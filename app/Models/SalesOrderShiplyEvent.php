<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderShiplyEvent extends Model
{
    protected $fillable = [
        'sales_order_id',
        'parcel_code',
        'parcel_status_id',
        'parcel_position_id',
        'note',
        'shiply_mode',
        'source',
        'occurred_at',
    ];

    protected $casts = [
        'parcel_status_id' => 'integer',
        'parcel_position_id' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
