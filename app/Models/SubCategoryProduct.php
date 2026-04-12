<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubCategoryProduct extends Model
{
    use HasFactory;
        protected $table = 'sub_category_products';

    protected $fillable = [
        'product_id',          // product ID
        'sub_category_id',   // sub-category ID
    ];

    public $incrementing = false;

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class, 'sub_category_id');
    }
}
