<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnSettlement extends Model
{
    protected $fillable = [
        'return_id', 'type', 'amount', 'currency', 'bill_id', 'box_id',
        'debt_transaction_id', 'notes', 'created_by',
    ];

    protected $casts = ['amount' => 'float'];

    public function purchaseReturn()
    {
        return $this->belongsTo(ReturnModel::class, 'return_id');
    }
}
