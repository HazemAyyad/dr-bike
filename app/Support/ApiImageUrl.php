<?php

namespace App\Support;

class ApiImageUrl
{
    /**
     * Normalize product/media paths for mobile clients (relative to public root).
     */
    public static function normalize(?string $imageUrl): string
    {
        if ($imageUrl === null || $imageUrl === '') {
            return 'no image';
        }

        $trimmed = trim($imageUrl);
        if ($trimmed === '') {
            return 'no image';
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }

        return ltrim(str_replace('\\', '/', $trimmed), '/');
    }
}
