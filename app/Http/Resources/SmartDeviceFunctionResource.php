<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmartDeviceFunctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'smart_device_id' => (int) $this->smart_device_id,
            'dp_id' => $this->dp_id,
            'code' => $this->code,
            'display_name' => $this->display_name,
            'function_type' => $this->function_type,
            'icon' => $this->icon,
            'sort_order' => (int) $this->sort_order,
            'is_visible' => (bool) $this->is_visible,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
