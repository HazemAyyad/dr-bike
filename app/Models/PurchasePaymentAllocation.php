<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchasePaymentAllocation extends Model
{
    protected $fillable = [
        'purchase_payment_id',
        'bill_id',
        'amount',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function payment()
    {
        return $this->belongsTo(PurchasePayment::class, 'purchase_payment_id');
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }
}
