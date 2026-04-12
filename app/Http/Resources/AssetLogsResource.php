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
                'date' => $this->created_at? $this->created_at->format('Y-m-d'):null,
                'depreciation_rate' => $this->asset->depreciation_rate?? 0,
                'total' => $this->total?? null,
                'type' => $this->type?? null,
            ];
    }
}
