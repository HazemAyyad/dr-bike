<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmartDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lastStatus = $this->last_status ?? [];
        $primaryPowerDp = $this->primaryPowerDp($lastStatus);

        return [
            'id' => (int) $this->id,
            'smart_home_id' => $this->smart_home_id !== null ? (int) $this->smart_home_id : null,
            'smart_room_id' => $this->smart_room_id !== null ? (int) $this->smart_room_id : null,
            'display_order' => (int) ($this->display_order ?? 0),
            'room' => $this->whenLoaded('room', fn () => $this->room ? [
                'id' => (int) $this->room->id,
                'name' => $this->room->name,
                'tuya_room_id' => $this->room->tuya_room_id,
            ] : null),
            'functions' => SmartDeviceFunctionResource::collection($this->whenLoaded('functions')),
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
            'last_status' => $lastStatus,
            'primary_power_dp' => $primaryPowerDp,
            'power_on' => $primaryPowerDp !== null ? (bool) ($lastStatus[$primaryPowerDp] ?? false) : null,
            'raw_metadata' => $this->when($request->boolean('include_debug'), $this->raw_metadata ?? []),
            'paired_at' => $this->paired_at?->toISOString(),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function primaryPowerDp(array $lastStatus): ?string
    {
        foreach (['switch_led', 'switch', 'power', '1'] as $key) {
            if (array_key_exists($key, $lastStatus)) {
                return $key;
            }
        }

        foreach ($lastStatus as $key => $value) {
            if (is_bool($value)) {
                return (string) $key;
            }
        }

        return null;
    }
}
