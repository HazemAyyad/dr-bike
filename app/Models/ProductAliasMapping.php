<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAliasMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'alias_text',
        'normalized_alias',
        'product_id',
        'times_used',
        'created_by',
        'updated_by',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'times_used' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
