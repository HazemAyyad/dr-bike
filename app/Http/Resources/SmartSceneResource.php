<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmartSceneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'smart_home_id' => (int) $this->smart_home_id,
            'smart_room_id' => $this->smart_room_id !== null ? (int) $this->smart_room_id : null,
            'tuya_scene_id' => $this->tuya_scene_id,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'trigger_type' => $this->trigger_type,
            'match_type' => $this->match_type,
            'conditions' => $this->conditions ?? [],
            'actions' => $this->actions ?? [],
            'enabled' => (bool) $this->enabled,
            'show_on_home' => (bool) $this->show_on_home,
            'show_in_room' => (bool) $this->show_in_room,
            'last_executed_at' => $this->last_executed_at?->toISOString(),
            'last_execution_status' => $this->last_execution_status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
