<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reward extends Model
{
    use HasFactory;
    protected $fillable = ['notes','employee_id','points','type'];

    public function employee(){
        return $this->belongsTo(EmployeeDetail::class,'employee_id');
    }
}
