<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;
    protected $fillable = ['name','description'];

    public function subDepartments(){
        return $this->hasMany(SubDepartment::class);
    }

    public function products(){
        return $this->hasMany(Product::class);
    }

    public function partnerships()
    {
        return $this->hasMany(Partnership::class);
    }
}
