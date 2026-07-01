<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SizeColor extends Model
{
    use HasFactory;
        protected $table = 'size_colors';

    protected $fillable = [
        'id',
        'colorAr',
        'sizeId', // foreign key to sizes
        'colorEn',
        'colorAbbr',
        'normailPrice',
        'wholesalePrice',
        'discount',
        'stock',
        'image_url',
        'meta_catalog_item_id',
        'meta_catalog_retailer_id',
        'meta_catalog_sync_status',
        'meta_catalog_last_synced_at',
        'meta_catalog_last_error',
        'meta_catalog_payload',
    ];

   public $incrementing = false;

    protected $casts = [
        'meta_catalog_last_synced_at' => 'datetime',
        'meta_catalog_payload' => 'array',
    ];

    public function size()
    {
        return $this->belongsTo(Size::class, 'sizeId');
    }
}
