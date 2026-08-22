<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseIssueResolution extends Model
{
    protected $fillable = [
        'bill_id',
        'bill_item_id',
        'purchase_receipt_item_id',
        'product_id',
        'issue_type',
        'resolution',
        'quantity',
        'negotiated_unit_price',
        'financial_adjustment',
        'reason',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'float',
        'negotiated_unit_price' => 'float',
        'financial_adjustment' => 'float',
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function billItem()
    {
        return $this->belongsTo(BillItem::class);
    }

    public function receiptItem()
    {
        return $this->belongsTo(PurchaseReceiptItem::class, 'purchase_receipt_item_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
