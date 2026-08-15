<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmartHomeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'tuya_home_id' => $this->tuya_home_id,
            'name' => $this->name,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'geo_name' => $this->geo_name,
            'is_default' => (bool) $this->is_default,
            'status' => $this->status,
            'rooms_count' => (int) ($this->rooms_count ?? 0),
            'devices_count' => (int) ($this->devices_count ?? 0),
            'online_devices_count' => (int) ($this->online_devices_count ?? 0),
            'offline_devices_count' => max(0, (int) ($this->devices_count ?? 0) - (int) ($this->online_devices_count ?? 0)),
            'raw_metadata' => $this->when($request->boolean('include_debug'), $this->raw_metadata ?? []),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
