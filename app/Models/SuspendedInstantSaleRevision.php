<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuspendedInstantSaleRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
    ];
}
