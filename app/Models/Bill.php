<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use HasFactory;
    protected $fillable = ['total','discount','seller_id','status'];

    public function items(){
        return $this->hasMany(BillItem::class,'bill_id');
    }
    public function seller(){
        return $this->belongsTo(Seller::class);
    }
}
