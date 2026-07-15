<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonProductPriceTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'person_product_setting_id',
        'min_qty',
        'max_qty',
        'unit_price',
    ];

    protected $casts = [
        'min_qty' => 'integer',
        'max_qty' => 'integer',
        'unit_price' => 'float',
    ];

    public function setting()
    {
        return $this->belongsTo(PersonProductSetting::class, 'person_product_setting_id');
    }
}
