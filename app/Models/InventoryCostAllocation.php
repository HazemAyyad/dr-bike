<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCostAllocation extends Model
{
    protected $fillable = [
        'inventory_cost_layer_id',
        'product_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'method',
        'reference_type',
        'reference_id',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_cost' => 'float',
        'total_cost' => 'float',
    ];
}
