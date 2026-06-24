<?php

namespace App\Models\Store;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreCategory extends Model
{
    protected $table = 'categories';

    public function subCategories(): HasMany
    {
        return $this->hasMany(StoreSubCategory::class, 'mainCategoryId');
    }
}
