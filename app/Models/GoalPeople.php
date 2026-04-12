<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalPeople extends Model
{
    use HasFactory;
    protected $table = 'goal_people';
    protected $fillable =['goal_id','customer_id','seller_id'];

    public function goal(){
        return $this->belongsTo(Goal::class);
    }

    public function customer(){
        return $this->belongsTo(Customer::class);
    }
    public function seller(){
        return $this->belongsTo(Seller::class);
    }
}
