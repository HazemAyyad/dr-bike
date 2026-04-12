<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalProduct extends Model
{
    use HasFactory;
        protected $table = 'goal_products';
    protected $fillable =['goal_id','product_id'];

    public function goal(){
        return $this->belongsTo(Goal::class);
    }

    public function product(){
        return $this->belongsTo(Product::class,'product_id');
    }
}
