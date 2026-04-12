<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Box extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'type',
        'employee_id',
        'total',
        'is_shown',
        'currency',
    ];

    public function employee()
    {
        return $this->belongsTo(EmployeeDetail::class,'employee_id');
    }

    public function draws(){
        return $this->hasMany(Draw::class);
    }

    public function deposits(){
        return $this->hasMany(Deposit::class);
    }

    public static function totalAmount()
    {
        return Box::where('is_shown',1)->sum('total');
    }

    public function incomingChecks(){
        return $this->hasMany(IncomingCheckBox::class);
    }

    public static function totalDollar(){
        return Box::where('is_shown',1)->where('currency','دولار')->sum('total');

    }

        public static function totalShekel(){
        return Box::where('is_shown',1)->where('currency','شيكل')->sum('total');

    }

        public static function totalDinar(){
        return Box::where('is_shown',1)->where('currency','دينار')->sum('total');

    }

}
