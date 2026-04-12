<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseProduct extends Model
{
    use HasFactory;
    protected $table = 'purchase_products';
    protected $fillable = ['price','seller_id','product_id'];

    public function product(){
        return $this->belongsTo(Product::class);
    }

    public function seller(){
        return $this->belongsTo(Seller::class);
    }
}
