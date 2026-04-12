<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SizeColor extends Model
{
    use HasFactory;
        protected $table = 'size_colors';

    protected $fillable = [
        'id',
        'colorAr',
        'sizeId', // foreign key to sizes
        'colorEn',
        'colorAbbr',
        'normailPrice',
        'wholesalePrice',
        'discount',
        'stock',
    ];

   public $incrementing = false;

    public function size()
    {
        return $this->belongsTo(Size::class, 'sizeId');
    }
}
