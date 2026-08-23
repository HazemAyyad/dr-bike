<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchasePayment extends Model
{
    protected $fillable = [
        'bill_id',
        'seller_id',
        'customer_id',
        'box_id',
        'amount',
        'currency',
        'type',
        'paid_at',
        'note',
        'debt_transaction_id',
        'box_log_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'date',
    ];

    public function allocations()
    {
        return $this->hasMany(PurchasePaymentAllocation::class);
    }
}
