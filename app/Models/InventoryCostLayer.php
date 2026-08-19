<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCostLayer extends Model
{
    protected $fillable = [
        'product_id',
        'size_id',
        'size_color_id',
        'quantity',
        'remaining_quantity',
        'unit_cost',
        'currency',
        'source_type',
        'source_id',
        'effective_at',
    ];

    protected $casts = [
        'quantity' => 'float',
        'remaining_quantity' => 'float',
        'unit_cost' => 'float',
        'effective_at' => 'datetime',
    ];
}
