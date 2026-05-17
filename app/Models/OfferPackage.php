<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfferPackage extends Model
{
    protected $fillable = [
        'name',
        'price',
        'package_quantity',
        'image_path',
        'is_active',
    ];

    protected $casts = [
        'price' => 'float',
        'package_quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OfferPackageItem::class);
    }

    public function instantSales(): HasMany
    {
        return $this->hasMany(InstantSale::class);
    }
}
