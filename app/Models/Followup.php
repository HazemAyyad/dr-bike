<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Followup extends Model
{
    use HasFactory;
    protected $table = 'followups';
    protected $fillable = [
        'customer_id',
        'product_id',
        'status',
        'start_date',
        'end_date',
        'notes',
        'is_canceled',
        'step',
        'seller_id',
    ];

    public function customer(){
        return $this->belongsTo(Customer::class);
    }

    public function product(){
        return $this->belongsTo(Product::class);
    }
    public function seller(){
        return $this->belongsTo(Seller::class);
    }
}