<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePointRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'condition_type' => $this->condition_type,
            'period_type' => $this->period_type,
            'operation_type' => $this->operation_type,
            'default_points' => (int) $this->default_points,
            'applies_to_all' => (bool) $this->applies_to_all,
            'settings' => $this->settings ?? [],
            'effective_from' => $this->effective_from?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'employee_ids' => $this->whenLoaded('employees', fn () => $this->employees->pluck('id')->map(fn ($id) => (int) $id)->values()),
            'overrides' => EmployeePointRuleOverrideResource::collection($this->whenLoaded('overrides')),
        ];
    }
}
