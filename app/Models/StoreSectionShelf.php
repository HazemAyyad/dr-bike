<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSectionShelf extends Model
{
    protected $fillable = [
        'store_section_id',
        'shelf_number',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(StoreSection::class, 'store_section_id');
    }
}
