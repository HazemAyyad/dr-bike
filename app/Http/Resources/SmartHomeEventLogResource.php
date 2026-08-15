<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmartHomeEventLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'user_id' => $this->user_id !== null ? (int) $this->user_id : null,
            'smart_home_id' => $this->smart_home_id !== null ? (int) $this->smart_home_id : null,
            'event' => $this->event,
            'success' => (bool) $this->success,
            'error_code' => $this->error_code,
            'message' => $this->message,
            'context' => $this->context ?? [],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
