<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiplyCity extends Model
{
    protected $fillable = [
        'shiply_id',
        'name',
        'mode',
        'deleted_at_remote',
    ];

    protected $casts = [
        'deleted_at_remote' => 'datetime',
    ];

    public function villages(): HasMany
    {
        return $this->hasMany(ShiplyVillage::class, 'shiply_city_id', 'shiply_id')
            ->whereColumn('shiply_villages.mode', 'shiply_cities.mode');
    }
}
