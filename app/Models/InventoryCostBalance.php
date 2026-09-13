<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCostBalance extends Model
{
    protected $fillable = [
        'product_id', 'size_id', 'size_color_id', 'identity_key', 'quantity',
        'inventory_value', 'moving_average_unit_cost', 'currency',
        'needs_review', 'review_reason',
    ];

    protected $casts = [
        'quantity' => 'float',
        'inventory_value' => 'float',
        'moving_average_unit_cost' => 'float',
        'needs_review' => 'boolean',
    ];
}
