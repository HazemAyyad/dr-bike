<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;
    protected $table = 'expenses';
      protected $fillable = [
        'name',
        'price',
        'payment_method',
        'notes',
        'invoice_img',
        'media','box_id'
    ];

    protected $casts = [
        'media' => 'array', // Cast JSON column to array
        'invoice_img' =>'array',
    ];

    public function box(){
        return $this->belongsTo(Box::class);
    }
}
