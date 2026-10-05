<?php

namespace App\Models\OnlineStore;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OnlineStoreMediaPresentation extends Model
{
    protected $fillable = ['online_store_listing_id', 'source_type', 'source_id', 'store_media_path', 'media_metadata', 'is_main', 'is_visible', 'sort_order'];

    protected $casts = ['source_id' => 'integer', 'media_metadata' => 'array', 'is_main' => 'boolean', 'is_visible' => 'boolean', 'sort_order' => 'integer'];

    public function listing()
    {
        return $this->belongsTo(OnlineStoreListing::class, 'online_store_listing_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
