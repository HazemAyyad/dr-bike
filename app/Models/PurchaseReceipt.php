<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceipt extends Model
{
    protected $fillable = [
        'bill_id',
        'receipt_number',
        'received_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'received_at' => 'date',
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }
}
