<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstantSaleRevision extends Model
{
    protected $fillable = [
        'instant_sale_id',
        'actor_user_id',
        'action',
        'before_snapshot',
        'after_snapshot',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'metadata' => 'array',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
