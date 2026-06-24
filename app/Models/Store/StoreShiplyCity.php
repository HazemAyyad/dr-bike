<?php

namespace App\Models\Store;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreShiplyCity extends Model
{
    protected $table = 'shiply_cities';

    public function villages(): HasMany
    {
        return $this->hasMany(StoreShiplyVillage::class, 'shiply_city_id', 'shiply_id')
            ->whereColumn('shiply_villages.mode', 'shiply_cities.mode');
    }
}
