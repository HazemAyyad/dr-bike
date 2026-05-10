<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePointsLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'employee_id' => (int) $this->employee_id,
            'points' => (int) $this->points,
            'operation_type' => (string) $this->operation_type,
            'category' => (string) $this->category,
            'source' => (string) $this->source,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'points_date' => optional($this->points_date)->toDateString(),
            'created_by' => $this->created_by ? (int) $this->created_by : null,
            'created_by_name' => $this->whenLoaded('creator', fn () => optional($this->creator)->name),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
