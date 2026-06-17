<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiplyVillage extends Model
{
    protected $fillable = [
        'shiply_id',
        'shiply_city_id',
        'name',
        'region_id',
        'region_type',
        'note',
        'is_closed',
        'mode',
        'deleted_at_remote',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
        'deleted_at_remote' => 'datetime',
    ];
}
