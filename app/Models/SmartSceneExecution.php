<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartSceneExecution extends Model
{
    protected $fillable = [
        'smart_scene_id', 'user_id', 'source', 'status', 'message', 'details', 'executed_at',
    ];

    protected $casts = [
        'details' => 'array',
        'executed_at' => 'datetime',
    ];

    public function scene(): BelongsTo
    {
        return $this->belongsTo(SmartScene::class, 'smart_scene_id');
    }
}
