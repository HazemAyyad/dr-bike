<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubDepartment extends Model
{
    use HasFactory;

    protected $table = 'sub_departments';
    protected $fillable = ['name','description','department_id'];

    public function products(){
        return $this->hasMany(Product::class);
    }

    public function department(){
        return $this->belongsTo(Department::class);
    }

    public function partnerships()
    {
        return $this->hasMany(Partnership::class);
    }
}
