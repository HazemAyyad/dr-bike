<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imagePath = null;

        if (is_array($this->media) && count($this->media) > 0) {
            foreach ($this->media as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'tiff', 'webp', 'avif', 'svg+xml'])) {
                    // found the first image → stop searching
                    $imagePath = 'public/AssetsMedia/images/'.$file;
                    break;
                }
            }
        }

        return [
            'asset_id' => $this->id,
            'name' => $this->name,
            'original_price' => $this->price,
            'depreciation_rate' => (float) $this->months_number > 0
                ? round(1 / (float) $this->months_number, 8)
                : 0,
            'depreciation_rate_percent' => (float) $this->months_number > 0
                ? round(100 / (float) $this->months_number, 6)
                : 0,
            'depreciation_price' => $this->depreciation_price,
            'months_number' => (int) $this->months_number,
            'acquired_at' => $this->acquired_at?->format('Y-m-d'),
            'depreciated_this_month' => (bool) ($this->depreciated_this_month ?? false),
            'depreciation_period' => now()->format('Y-m'),
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d') : null,
            'image' => $imagePath ?? 'no image files',

        ];
    }
}
