<?php

namespace App\Models\Store;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class StoreUser extends Authenticatable
{
    use HasApiTokens;
    use SoftDeletes;

    protected $table = 'users';

    protected $hidden = [
        'password',
        'remember_token',
    ];
}
