<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ViewImageProduct extends Model
{
    use HasFactory;
    protected $table = 'view_image_products';

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
