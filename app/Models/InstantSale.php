<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InstantSale extends Model
{
    use HasFactory;
    protected $table = 'instant_sales';
    protected $fillable = ['product_id','parent_id','total_cost',
    'cost','notes','quantity',
    'discount','project_id','type'];

    public function product(){
        return $this->belongsTo(Product::class);
    }

    public function subProducts()
{
    return $this->hasMany(InstantSale::class, 'parent_id');
}

    public function project(){
        return $this->belongsTo(Project::class);
    }

}
