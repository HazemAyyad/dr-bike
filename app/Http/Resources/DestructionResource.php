<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DestructionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $imagePath = [];

            if (is_array($this->media) && count($this->media) > 0) {
                foreach ($this->media as $file) {
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                    if (in_array($extension,['jpg', 'jpeg', 'png','gif','tiff','webp','avif','svg+xml'])) {
                        // found the first image → stop searching
                        $imagePath[] = 'public/DestructionsMedia/images/' . $file;
                    }
                    elseif(in_array($extension,['mp4','quicktime','x-msvideo','x-ms-wmv','x-matroska','webm'])){
                        $imagePath[] = 'public/DestructionsMedia/videos/' . $file;
 
                    }
                }
            }
        return [
            'destruction_id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product->nameAr?? 'no name',
            'destruction_value'=> $this->product->normailPrice * $this->pieces_number,
            'pieces_number' => $this->pieces_number,
            'destruction_reason' => $this->destruction_reason,
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d') : null,
            'image' => $imagePath,
        ];   
     }
}
