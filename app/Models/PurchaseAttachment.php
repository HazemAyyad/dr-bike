<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseAttachment extends Model
{
    protected $fillable = [
        'bill_id',
        'attachable_type',
        'attachable_id',
        'category',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'created_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];
}
