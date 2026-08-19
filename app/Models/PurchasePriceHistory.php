<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchasePriceHistory extends Model
{
    protected $fillable = [
        'product_id',
        'seller_id',
        'customer_id',
        'bill_id',
        'bill_item_id',
        'purchase_receipt_item_id',
        'unit_price',
        'quantity',
        'currency',
        'priced_at',
        'manual_override',
        'created_by',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'quantity' => 'float',
        'priced_at' => 'date',
        'manual_override' => 'boolean',
    ];
}
