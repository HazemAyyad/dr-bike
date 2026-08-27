<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Picture extends Model
{
    use HasFactory;
    protected $fillable = [
        'name','description','file','is_cancelled','cancelled_at','cancelled_by_user_id',
    ];

    protected $casts = [
        'is_cancelled' => 'boolean',
        'cancelled_at' => 'datetime',
    ];
}
