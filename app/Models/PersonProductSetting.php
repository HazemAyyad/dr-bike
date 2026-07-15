<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonProductSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'customer_id',
        'seller_id',
        'custom_price',
        'is_hidden',
    ];

    protected $casts = [
        'custom_price' => 'float',
        'is_hidden' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function priceTiers()
    {
        return $this->hasMany(PersonProductPriceTier::class)->orderBy('min_qty');
    }
}
