<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductDevelopment extends Model
{
    use HasFactory;
    protected $table = 'product_development';
    protected $fillable = ['step','description','product_id'];

    public function product(){
        return $this->belongsTo(Product::class);
    }
}
