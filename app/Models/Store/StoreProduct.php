<?php

namespace App\Models\Store;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreProduct extends Model
{
    protected $table = 'products';

    public function category(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class, 'category_id');
    }

    public function subCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            StoreSubCategory::class,
            'sub_category_products',
            'product_id',
            'sub_category_id'
        );
    }

    public function normalImages(): HasMany
    {
        return $this->hasMany(StoreNormalImageProduct::class, 'itemId');
    }

    public function viewImages(): HasMany
    {
        return $this->hasMany(StoreViewImageProduct::class, 'itemId');
    }

    public function image3d(): HasMany
    {
        return $this->hasMany(StoreImage3dProduct::class, 'itemId');
    }

    public function sizes(): HasMany
    {
        return $this->hasMany(StoreSize::class, 'itemId');
    }
}
