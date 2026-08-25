<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmartScene extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'smart_home_id', 'smart_room_id', 'tuya_scene_id', 'name',
        'icon', 'color', 'trigger_type', 'match_type', 'conditions', 'actions',
        'enabled', 'show_on_home', 'show_in_room', 'last_executed_at',
        'last_execution_status',
    ];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'enabled' => 'boolean',
        'show_on_home' => 'boolean',
        'show_in_room' => 'boolean',
        'last_executed_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function home(): BelongsTo
    {
        return $this->belongsTo(SmartHome::class, 'smart_home_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(SmartRoom::class, 'smart_room_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(SmartSceneExecution::class);
    }
}
