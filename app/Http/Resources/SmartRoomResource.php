<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmartRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'smart_home_id' => (int) $this->smart_home_id,
            'tuya_room_id' => $this->tuya_room_id,
            'name' => $this->name,
            'sort_order' => (int) $this->sort_order,
            'devices_count' => (int) ($this->devices_count ?? 0),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
