<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetLogsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'asset_id' => $this->asset_id,
            'asset_name' => $this->asset->name,
            'date' => $this->created_at ? $this->created_at->format('Y-m-d') : null,
            'depreciation_rate' => (float) ($this->asset->months_number ?? 0) > 0
                ? round(1 / (float) $this->asset->months_number, 8)
                : 0,
            'depreciation_rate_percent' => (float) ($this->asset->months_number ?? 0) > 0
                ? round(100 / (float) $this->asset->months_number, 6)
                : 0,
            'total' => $this->total ?? null,
            'type' => $this->type ?? null,
            'depreciation_period' => $this->depreciation_period,
            'value_before' => $this->value_before,
            'depreciation_amount' => $this->depreciation_amount,
        ];
    }
}
