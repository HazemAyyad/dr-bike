<?php

namespace App\Models\OnlineStore;

use Illuminate\Database\Eloquent\Model;

class OnlineStoreHomeSectionItem extends Model
{
    protected $fillable = ['home_section_id', 'target_type', 'target_id', 'sort_order'];

    protected $casts = ['target_id' => 'integer', 'sort_order' => 'integer'];

    public function section()
    {
        return $this->belongsTo(OnlineStoreHomeSection::class, 'home_section_id');
    }
}
