<?php

namespace App\Models\OnlineStore;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OnlineStoreListing extends Model
{
    protected $table = 'online_store_listings';

    protected $fillable = [
        'product_id', 'name_translations', 'description_translations', 'badge_translations',
        'is_featured', 'is_new', 'show_on_home', 'show_as_offer', 'sort_order',
    ];

    protected $casts = [
        'name_translations' => 'array',
        'description_translations' => 'array',
        'badge_translations' => 'array',
        'readiness_issues' => 'array',
        'is_featured' => 'boolean',
        'is_new' => 'boolean',
        'show_on_home' => 'boolean',
        'show_as_offer' => 'boolean',
        'sort_order' => 'integer',
        'published_at' => 'datetime',
        'hidden_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
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
