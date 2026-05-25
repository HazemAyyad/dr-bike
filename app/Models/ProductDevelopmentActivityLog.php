<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductDevelopmentActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_development_id',
        'user_id',
        'action',
        'description',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function productDevelopment()
    {
        return $this->belongsTo(ProductDevelopment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
