<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnModel extends Model
{
    use HasFactory;
    protected $table = 'returns';

    protected $fillable = [
        'seller_id',
        'total',
        'status',
    ];


    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }

    public function items(){
        return $this->hasMany(PurchaseReturn::class,'return_id');
    }

}
