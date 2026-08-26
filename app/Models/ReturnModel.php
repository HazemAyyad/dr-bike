<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnModel extends Model
{
    use HasFactory;
    protected $table = 'returns';

    protected $fillable = [
        'number', 'bill_id', 'seller_id', 'customer_id',
        'total',
        'currency',
        'status',
        'settled_amount', 'reason', 'resolution', 'refund_box_id',
        'debt_transaction_id',
        'note', 'notes', 'created_by', 'confirmed_by', 'delivered_by', 'settled_by',
        'cancelled_by', 'confirmed_at', 'delivered_at', 'settled_at',
        'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'total' => 'float', 'settled_amount' => 'float',
        'confirmed_at' => 'datetime', 'delivered_at' => 'datetime',
        'settled_at' => 'datetime', 'cancelled_at' => 'datetime',
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
        return $this->hasMany(PurchaseReturn::class, 'return_id');
    }

    public function settlements()
    {
        return $this->hasMany(PurchaseReturnSettlement::class, 'return_id');
    }

    public function activityLogs()
    {
        return $this->hasMany(PurchaseActivityLog::class, 'source_id')
            ->where('source_type', 'purchase_return')
            ->latest('id');
    }

}
