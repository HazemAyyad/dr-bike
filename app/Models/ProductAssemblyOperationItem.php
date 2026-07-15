<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAssemblyOperationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'operation_id',
        'component_product_id',
        'component_size_color_id',
        'quantity_per_unit',
        'total_quantity',
        'unit_cost',
        'total_cost',
    ];

    protected $casts = [
        'quantity_per_unit' => 'float',
        'total_quantity' => 'float',
        'unit_cost' => 'float',
        'total_cost' => 'float',
    ];

    public function operation()
    {
        return $this->belongsTo(ProductAssemblyOperation::class, 'operation_id');
    }

    public function componentProduct()
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function componentSizeColor()
    {
        return $this->belongsTo(SizeColor::class, 'component_size_color_id');
    }
}
