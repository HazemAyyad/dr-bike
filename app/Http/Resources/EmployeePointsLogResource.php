<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EmployeePointsLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $categoryRelation = $this->whenLoaded('categoryRelation');

        return [
            'id' => (int) $this->id,
            'employee_id' => (int) $this->employee_id,
            'points' => (int) $this->points,
            'operation_type' => (string) $this->operation_type,
            'category' => (string) $this->category,
            'category_id' => $this->category_id ? (int) $this->category_id : null,
            'category_name_ar' => $categoryRelation && is_object($categoryRelation)
                ? $categoryRelation->name_ar
                : null,
            'category_name_en' => $categoryRelation && is_object($categoryRelation)
                ? $categoryRelation->name_en
                : null,
            'source' => (string) $this->source,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'image_url' => $this->image_path
                ? Storage::disk('public')->url($this->image_path)
                : null,
            'points_date' => optional($this->points_date)->toDateString(),
            'created_by' => $this->created_by ? (int) $this->created_by : null,
            'created_by_name' => $this->whenLoaded('creator', fn () => optional($this->creator)->name),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
