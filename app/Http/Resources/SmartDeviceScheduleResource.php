<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmartDeviceScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $commands = $this->commands;
        if (! is_array($commands) || $commands === []) {
            $commands = [[
                'command_code' => $this->command_code,
                'command_value' => $this->command_value ?? [],
            ]];
        }

        return [
            'id' => (int) $this->id,
            'smart_device_id' => (int) $this->smart_device_id,
            'name' => $this->name,
            'command_code' => $this->command_code,
            'command_value' => $this->command_value ?? [],
            'commands' => $commands,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'repeat_type' => $this->repeat_type,
            'repeat_days' => $this->repeat_days ?? [],
            'recurrence_config' => $this->recurrence_config ?? [],
            'enabled' => (bool) $this->enabled,
            'last_executed_at' => $this->last_executed_at?->toISOString(),
            'next_run_at' => $this->next_run_at?->toISOString(),
        ];
    }
}
