<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Punishment extends Model
{
    use HasFactory;
    protected $fillable = ['punishment','employee_id','price'];

    public function employee(){
        return $this->belongsTo(EmployeeDetail::class,'employee_id');
    }
}
