<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Size extends Model
{
    use HasFactory;
       protected $table = 'sizes';

    protected $fillable = [
        'id',
        'size',
        'itemId', // product ID
        'discount',
        'description',
    ];

   public $incrementing = false;

    public function product()
    {
        return $this->belongsTo(Product::class, 'itemId');
    }

    public function colorSizes()
    {
        return $this->hasMany(SizeColor::class, 'sizeId');
    }
}
