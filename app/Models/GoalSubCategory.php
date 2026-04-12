<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalSubCategory extends Model
{
    use HasFactory;
        protected $table = 'goal_sub_categories';
    protected $fillable =['goal_id','sub_category_id'];

    public function goal(){
        return $this->belongsTo(Goal::class);
    }

    public function subCategory(){
        return $this->belongsTo(SubCategory::class,'sub_category_id');
    }
}
