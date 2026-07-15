<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAssemblyOperation extends Model
{
    use HasFactory;

    public const TYPE_ASSEMBLE = 'assemble';

    public const TYPE_DISASSEMBLE = 'disassemble';

    protected $fillable = [
        'recipe_id',
        'operation_type',
        'target_product_id',
        'target_size_color_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'note',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'float',
        'total_cost' => 'float',
    ];

    public function recipe()
    {
        return $this->belongsTo(ProductAssemblyRecipe::class, 'recipe_id');
    }

    public function targetProduct()
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }

    public function targetSizeColor()
    {
        return $this->belongsTo(SizeColor::class, 'target_size_color_id');
    }

    public function items()
    {
        return $this->hasMany(ProductAssemblyOperationItem::class, 'operation_id');
    }
}
