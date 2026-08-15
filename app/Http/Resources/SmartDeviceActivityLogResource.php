<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmartDeviceActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'smart_device_id' => (int) $this->smart_device_id,
            'user_id' => $this->user_id !== null ? (int) $this->user_id : null,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => (int) $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'action' => $this->action,
            'command_code' => $this->command_code,
            'command_value' => $this->command_value,
            'success' => (bool) $this->success,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
