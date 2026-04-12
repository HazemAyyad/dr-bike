<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectProduct extends Model
{
    use HasFactory;
    protected $table = 'project_products';
    protected $fillable = ['product_id','project_id'];

    public function product(){
        return $this->belongsTo(Product::class);
    }

    public function project(){
        return $this->belongsTo(Project::class);
    }
}
