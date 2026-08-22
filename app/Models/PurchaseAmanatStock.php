<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseAmanatStock extends Model
{
    protected $fillable = [
        'bill_id',
        'bill_item_id',
        'product_id',
        'purchase_receipt_item_id',
        'quantity',
        'remaining_quantity',
        'status',
        'negotiated_unit_price',
        'notes',
        'created_by',
        'resolved_at',
    ];

    protected $casts = [
        'quantity' => 'float',
        'remaining_quantity' => 'float',
        'negotiated_unit_price' => 'float',
        'resolved_at' => 'datetime',
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function billItem()
    {
        return $this->belongsTo(BillItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
