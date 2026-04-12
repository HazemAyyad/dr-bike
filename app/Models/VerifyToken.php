<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerifyToken extends Model
{
    use HasFactory;
    protected $table = 'verify_tokens';

    protected $fillable = [
        'email',
        'token',
        'is_activated',
    ];
}
