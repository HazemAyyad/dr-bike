<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncomingCheck extends Model
{
    use HasFactory;
        protected $table = 'incoming_checks';

        protected $fillable = [
        'from_customer',
        'from_seller',
        'to_customer',
        'to_seller',
        'total',
        'due_date',
        'currency',
        'check_id',
        'bank_name',
        'front_image',
        'back_image',
        'status',
        'notes',
    ];

        // Relationships
    public function fromCustomer()
    {
        return $this->belongsTo(Customer::class, 'from_customer');
    }

    public function toCustomer()
    {
        return $this->belongsTo(Customer::class, 'to_customer');
    }

    public function fromSeller()
    {
        return $this->belongsTo(Seller::class, 'from_seller');
    }

    public function toSeller()
    {
        return $this->belongsTo(Seller::class, 'to_seller');
    }

    public function boxes(){
        return $this->hasMany(IncomingCheckBox::class,'incoming_check_id');
    }

    public static function incomingChecksCount(){
        return IncomingCheck::count();
    }

    //total checks values
    public static function totalAmount(){
        return IncomingCheck::where('status','not_cashed')->sum('total');
    }
}
