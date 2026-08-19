<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenanceProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_id',
        'product_id',
        'size_id',
        'size_color_id',
        'quantity',
        'unit_price',
        'line_total',
        'inventory_cost_method',
        'inventory_unit_cost',
        'inventory_total_cost',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'float',
        'line_total' => 'float',
        'inventory_unit_cost' => 'float',
        'inventory_total_cost' => 'float',
    ];

    public function maintenance()
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
