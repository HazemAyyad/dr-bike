<?php

namespace App\Models\OnlineStore;

use Illuminate\Database\Eloquent\Model;

class OnlineStoreCategoryListing extends Model
{
    protected $table = 'online_store_category_listing';

    protected $fillable = ['online_store_category_id', 'online_store_listing_id', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function category()
    {
        return $this->belongsTo(OnlineStoreCategory::class, 'online_store_category_id');
    }

    public function listing()
    {
        return $this->belongsTo(OnlineStoreListing::class, 'online_store_listing_id');
    }
}
