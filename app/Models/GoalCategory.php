<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalCategory extends Model
{
    use HasFactory;
    protected $table = 'goal_categories';
    protected $fillable =['goal_id','category_id'];

    public function goal(){
        return $this->belongsTo(Goal::class);
    }

    public function category(){
        return $this->belongsTo(Category::class,'category_id');
    }
}
