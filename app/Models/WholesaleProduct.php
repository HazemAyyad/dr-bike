<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WholesaleProduct extends Model
{
    use HasFactory;
    protected $table = 'wholesale_products';
    protected $fillable = ['price','pieces','product_id'];

    public function product(){
        return $this->belongsTo(Product::class);
    }
}
