<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePointRuleOverrideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'rule_id' => (int) $this->rule_id,
            'employee_id' => (int) $this->employee_id,
            'rule_name' => $this->whenLoaded('rule', fn () => $this->rule?->name),
            'points' => $this->points === null ? null : (int) $this->points,
            'operation_type' => $this->operation_type,
            'is_excluded' => (bool) $this->is_excluded,
            'effective_from' => $this->effective_from?->toDateString(),
            'notes' => $this->notes,
        ];
    }
}
