<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubCategory extends Model
{
    use HasFactory;
        public $incrementing = false; // allow manual ID assignment

    protected $fillable = [
        'id',
        'nameAr',
        'nameEng',
        'nameAbree',
        'descriptionAr',
        'descriptionEng',
        'descriptionAbree',
        'imageUrl',
        'isShow',
        'sortOrder',
        'mainCategoryId',
        'userAdd',
        'dateAdd',
        'userEdit',
        'dateEdit',
    ];

        public function category()
    {
        return $this->belongsTo(Category::class, 'mainCategoryId');
    }

        public function products(){
        return $this->hasMany(SubCategoryProduct::class,'sub_category_id');
    }
}
