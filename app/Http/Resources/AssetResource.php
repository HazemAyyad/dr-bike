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

                    if (in_array($extension, ['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])) {
                        // found the first image → stop searching
                        $imagePath = 'public/AssetsMedia/images/' . $file;
                        break;
                    }
                }
            }
        return [
            'asset_id' => $this->id,
            'name' => $this->name,
            'original_price' => $this->price,
            'depreciation_rate' => $this->depreciation_rate,
            'depreciation_price' => $this->depreciation_price,
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d') : null,
            'image' => $imagePath ?? 'no image files',

        ];
    }
}
