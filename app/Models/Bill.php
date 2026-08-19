<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use HasFactory;
    protected $fillable = [
        'total',
        'discount',
        'seller_id',
        'customer_id',
        'status',
        'workflow_status',
        'currency',
        'final_total',
        'paid_amount',
        'payment_status',
        'notes',
        'finalized_at',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'total' => 'float',
        'discount' => 'float',
        'final_total' => 'float',
        'paid_amount' => 'float',
        'finalized_at' => 'datetime',
    ];

    public function items(){
        return $this->hasMany(BillItem::class,'bill_id');
    }
    public function seller(){
        return $this->belongsTo(Seller::class);
    }

    public function customer(){
        return $this->belongsTo(Customer::class);
    }

    public function receipts(){
        return $this->hasMany(PurchaseReceipt::class,'bill_id');
    }

    public function payments(){
        return $this->hasMany(PurchasePayment::class,'bill_id');
    }

    public function activityLogs(){
        return $this->hasMany(PurchaseActivityLog::class,'bill_id');
    }
}
