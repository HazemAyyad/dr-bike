<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfitSale extends Model
{
    use HasFactory;
    protected $table = 'profit_sales';
    protected $fillable = ['total_cost','notes'];

}
