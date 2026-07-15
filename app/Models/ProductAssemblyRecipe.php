<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAssemblyRecipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'target_product_id',
        'target_size_color_id',
        'name',
        'unit_cost',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'unit_cost' => 'float',
        'is_active' => 'boolean',
    ];

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
        return $this->hasMany(ProductAssemblyRecipeItem::class, 'recipe_id');
    }

    public function operations()
    {
        return $this->hasMany(ProductAssemblyOperation::class, 'recipe_id');
    }
}
