<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAssemblyRecipeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'component_product_id',
        'component_size_color_id',
        'quantity_per_unit',
        'unit_cost',
    ];

    protected $casts = [
        'quantity_per_unit' => 'float',
        'unit_cost' => 'float',
    ];

    public function recipe()
    {
        return $this->belongsTo(ProductAssemblyRecipe::class, 'recipe_id');
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
