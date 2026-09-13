<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCostReview extends Model
{
    protected $fillable = [
        'product_id', 'size_id', 'size_color_id', 'identity_key',
        'physical_quantity', 'costed_quantity', 'missing_quantity',
        'status', 'reason', 'evidence', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'physical_quantity' => 'float', 'costed_quantity' => 'float',
        'missing_quantity' => 'float', 'evidence' => 'array', 'resolved_at' => 'datetime',
    ];
}
