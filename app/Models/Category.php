<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    protected $table = 'categories';

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
    'userAdd',
    'dateAdd',
    'userEdit',
    'dateEdit',
];

public $incrementing = false;

  public function subCategories()
    {
        return $this->hasMany(SubCategory::class, 'mainCategoryId');
    }

}
