<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderPurgeBackup extends Model
{
    protected $fillable = [
        'reference',
        'cutoff_at',
        'max_order_id',
        'orders_count',
        'mode',
        'status',
        'payload',
        'created_by',
        'restored_by',
        'restored_at',
    ];

    protected $hidden = ['payload'];

    protected $casts = [
        'cutoff_at' => 'datetime',
        'payload' => 'array',
        'restored_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }
}
