<?php

namespace App\Models\OnlineStore;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OnlineStoreCategory extends Model
{
    protected $fillable = ['parent_id', 'name_translations', 'description_translations', 'image_path', 'is_active', 'show_on_home', 'sort_order'];

    protected $casts = ['name_translations' => 'array', 'description_translations' => 'array', 'is_active' => 'boolean', 'show_on_home' => 'boolean', 'sort_order' => 'integer'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function memberships()
    {
        return $this->hasMany(OnlineStoreCategoryListing::class, 'online_store_category_id');
    }

    public function listings()
    {
        return $this->belongsToMany(OnlineStoreListing::class, 'online_store_category_listing')->withPivot('sort_order')->withTimestamps()->orderByPivot('sort_order')->orderBy('online_store_listings.id');
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
