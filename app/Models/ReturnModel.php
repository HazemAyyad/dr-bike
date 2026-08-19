<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnModel extends Model
{
    use HasFactory;
    protected $table = 'returns';

    protected $fillable = [
        'seller_id',
        'customer_id',
        'bill_id',
        'total',
        'currency',
        'status',
        'resolution',
        'refund_box_id',
        'debt_transaction_id',
        'note',
        'created_by',
        'delivered_at',
    ];

    protected $casts = [
        'total' => 'float',
        'delivered_at' => 'datetime',
    ];

    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function items(){
        return $this->hasMany(PurchaseReturn::class,'return_id');
    }

}
