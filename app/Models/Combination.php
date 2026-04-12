<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Combination extends Model
{
    use HasFactory;
    protected $fillable = ['quantity','added_product_id','main_product_id'];

    public function mainProduct(){
        return $this->belongsTo(Product::class,'main_product_id');
    }

    public function addedProduct(){
        return $this->belongsTo(Product::class,'added_product_id');
    }
}
