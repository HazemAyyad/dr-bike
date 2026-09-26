<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseActivityLog extends Model
{
    protected $fillable = [
        'bill_id',
        'event',
        'title',
        'description',
        'before_values',
        'after_values',
        'meta',
        'source_type',
        'source_id',
        'created_by',
    ];

    protected $casts = [
        'before_values' => 'array',
        'after_values' => 'array',
        'meta' => 'array',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
