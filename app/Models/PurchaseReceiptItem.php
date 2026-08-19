<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceiptItem extends Model
{
    protected $fillable = [
        'purchase_receipt_id',
        'bill_item_id',
        'product_id',
        'size_id',
        'size_color_id',
        'accepted_quantity',
        'missing_quantity',
        'extra_quantity',
        'damaged_quantity',
        'mismatched_quantity',
        'unit_price',
        'resolution',
        'reason',
        'notes',
    ];

    protected $casts = [
        'accepted_quantity' => 'float',
        'missing_quantity' => 'float',
        'extra_quantity' => 'float',
        'damaged_quantity' => 'float',
        'mismatched_quantity' => 'float',
        'unit_price' => 'float',
    ];

    public function receipt()
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
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
