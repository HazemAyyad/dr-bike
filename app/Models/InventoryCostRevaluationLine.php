<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCostRevaluationLine extends Model
{
    protected $fillable = [
        'inventory_adjustment_id', 'inventory_cost_layer_id', 'quantity',
        'old_unit_cost', 'new_unit_cost', 'old_value', 'new_value',
    ];

    protected $casts = [
        'quantity' => 'float', 'old_unit_cost' => 'float', 'new_unit_cost' => 'float',
        'old_value' => 'float', 'new_value' => 'float',
    ];
}
