<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Image3dProduct extends Model
{
    use HasFactory;
        protected $table = 'image3d_products';

    protected $fillable = [
        'id',
        'itemId',
        'imageUrl',
    ];

   public $incrementing = false;

    public function product()
    {
        return $this->belongsTo(Product::class, 'itemId');
    }
}
