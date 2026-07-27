<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockImageExport extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'filters',
        'source_summary',
        'total_products',
        'processed_products',
        'images_added',
        'file_name',
        'file_path',
        'file_size',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'source_summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
