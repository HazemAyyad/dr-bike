<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAdjustment extends Model
{
    public const TYPE_QUANTITY = 'quantity';
    public const TYPE_COST_REVALUATION = 'cost_revaluation';
    public const TYPE_COST_INITIALIZATION = 'cost_initialization';

    protected $fillable = [
        'reference', 'product_id', 'size_id', 'size_color_id', 'adjustment_type',
        'stock_before', 'stock_after', 'quantity_difference', 'old_unit_cost',
        'new_unit_cost', 'old_value', 'new_value', 'value_difference', 'currency',
        'costing_method', 'reason', 'notes', 'created_by', 'reversal_of_id',
        'reversed_at', 'reversed_by',
    ];

    protected $casts = [
        'stock_before' => 'float', 'stock_after' => 'float',
        'quantity_difference' => 'float', 'old_unit_cost' => 'float',
        'new_unit_cost' => 'float', 'old_value' => 'float', 'new_value' => 'float',
        'value_difference' => 'float', 'reversed_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function size()
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    public function sizeColor()
    {
        return $this->belongsTo(SizeColor::class, 'size_color_id');
    }
}
