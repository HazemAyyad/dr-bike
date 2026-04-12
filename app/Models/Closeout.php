<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Closeout extends Model
{
    use HasFactory;

    protected $table = 'closeouts';
    protected $fillable = ['product_id','status'];

    public function product(){
        return $this->belongsTo(Product::class);
    }
}
