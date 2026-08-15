<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmartDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'smart_home_id' => (int) $this->smart_home_id,
            'smart_room_id' => $this->smart_room_id !== null ? (int) $this->smart_room_id : null,
            'room' => $this->whenLoaded('room', fn () => $this->room ? [
                'id' => (int) $this->room->id,
                'name' => $this->room->name,
                'tuya_room_id' => $this->room->tuya_room_id,
            ] : null),
            'tuya_device_id' => $this->tuya_device_id,
            'tuya_product_id' => $this->tuya_product_id,
            'tuya_uuid' => $this->tuya_uuid,
            'name' => $this->name,
            'category' => $this->category,
            'product_name' => $this->product_name,
            'icon' => $this->icon,
            'protocol' => $this->protocol,
            'online' => (bool) $this->online,
            'model' => $this->model,
            'manufacturer' => $this->manufacturer,
            'last_status' => $this->last_status ?? [],
            'raw_metadata' => $this->when($request->boolean('include_debug'), $this->raw_metadata ?? []),
            'paired_at' => $this->paired_at?->toISOString(),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
