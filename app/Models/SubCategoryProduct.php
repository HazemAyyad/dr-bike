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

    /**
     * Remove pivot rows whose subcategory does not belong to the product's main category.
     */
    public static function deleteForProductOutsideMain(int $productId, int $mainCategoryId): int
    {
        return (int) static::query()
            ->where('product_id', $productId)
            ->where(function ($q) use ($mainCategoryId) {
                $q->whereDoesntHave('subCategory')
                    ->orWhereHas('subCategory', function ($sq) use ($mainCategoryId) {
                        $sq->where('mainCategoryId', '!=', $mainCategoryId);
                    });
            })
            ->delete();
    }
}
