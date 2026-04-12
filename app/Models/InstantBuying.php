<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InstantBuying extends Model
{
    use HasFactory;
    protected $table = 'instant_buyings';
    protected $fillable = ['customer_id','total','payment_method'];

    public function products(){
        return $this->belongsToMany(Product::class,'instant_buying_product')
        ->withTimestamps()
        ->withPivot('quantity');
        
    }

    public function customer(){
        return $this->belongsTo(Customer::class);
    }

}
