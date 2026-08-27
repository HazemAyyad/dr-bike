<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Destruction extends Model
{
    use HasFactory;
    protected $fillable = [
        'product_id','pieces_number',
        'destruction_reason','media','cost_method','unit_cost','total_cost','created_by_user_id'
    ];

    protected $casts = ['media'=>'array'];

    public function expense(){
        return $this->hasOne(Expense::class);
    }

    public function product(){
        return $this->belongsTo(Product::class);
    }
}
