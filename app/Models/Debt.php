<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Debt extends Model
{
    use HasFactory;
    protected $fillable = [
        'customer_id',
        'type',
        'due_date',
        'total',
        'receipt_image',
        'notes',
        'status',
        'seller_id',
        'bill_id',
        'return_id',
    ];

        protected $casts = ['receipt_image'=>'array'];

    /**
     * Get the customer that owns the debt.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }
}
