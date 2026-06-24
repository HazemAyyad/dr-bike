<?php

namespace App\Models\Store;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StoreSubCategory extends Model
{
    protected $table = 'sub_categories';

    public function category(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class, 'mainCategoryId');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            StoreProduct::class,
            'sub_category_products',
            'sub_category_id',
            'product_id'
        );
    }
}
