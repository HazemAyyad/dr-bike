<?php

namespace App\Models\Store;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreSize extends Model
{
    protected $table = 'sizes';

    public function colors(): HasMany
    {
        return $this->hasMany(StoreSizeColor::class, 'sizeId');
    }
}
