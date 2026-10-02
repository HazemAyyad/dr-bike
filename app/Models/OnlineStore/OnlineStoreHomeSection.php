<?php

namespace App\Models\OnlineStore;

use Illuminate\Database\Eloquent\Model;

class OnlineStoreHomeSection extends Model
{
    protected $fillable = ['key', 'section_type', 'title_translations', 'selection_mode', 'selection_config', 'is_visible', 'sort_order'];

    protected $casts = ['title_translations' => 'array', 'selection_config' => 'array', 'is_visible' => 'boolean', 'sort_order' => 'integer'];

    public function items()
    {
        return $this->hasMany(OnlineStoreHomeSectionItem::class, 'home_section_id')->orderBy('sort_order')->orderBy('id');
    }
}
