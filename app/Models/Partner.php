<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use HasFactory;
    protected $fillable = ['name','age','job_title'];
    
    public function partnerships()
    {
        return $this->hasMany(Partnership::class);
    }
}
