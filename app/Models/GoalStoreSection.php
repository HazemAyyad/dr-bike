<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalStoreSection extends Model
{
    use HasFactory;

    protected $table = 'goal_store_sections';

    protected $fillable = ['goal_id', 'store_section_id'];

    public function goal()
    {
        return $this->belongsTo(Goal::class);
    }
}
