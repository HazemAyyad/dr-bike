<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use HasFactory;
    protected $table = 'deposits';
       protected $fillable = [
        'deposit_way',
        'customer_id',
        'box_id',
        'receipt_image',
        'total',
        'parent_id',
        'notes',
    ];

    public function customer(){
        return $this->belongsTo(Customer::class);
    }

    public function box(){
        return $this->belongsTo(Box::class);
    }
}
