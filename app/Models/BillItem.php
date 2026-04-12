<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillItem extends Model
{
    use HasFactory;
    protected $table = 'bill_items';
    protected $fillable = ['quantity','product_id','bill_id','status',
    'price','extra_amount','missing_amount','not_compatible_amount','not_compatible_description'];

    public function bill(){
        return $this->belongsTo(Bill::class,'bill_id');
    }

    public function product(){
        return $this->belongsTo(Product::class);
    }
}
