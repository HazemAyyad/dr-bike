<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Destruction extends Model
{
    use HasFactory;
    protected $fillable = [
        'product_id','pieces_number',
        'destruction_reason','media'
    ];

    protected $casts = ['media'=>'array'];

    public function product(){
        return $this->belongsTo(Product::class);
    }
}
