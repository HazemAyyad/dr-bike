<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;

class ThumbnailHelper
{
    public const THUMB_WIDTH   = 400;
    public const THUMB_QUALITY = 75;

    /**
     * Generate a thumbnail for a file stored on a Laravel disk.
     * Thumbnail is placed in a `thumb/` subdirectory next to the original.
     *
     * @param  string  $diskPath  Relative path on the disk (e.g. "product-uploads/1/normal_images/file.jpg")
     * @param  string  $disk      Laravel disk name (default: 'public')
     */
    public static function makeThumbForDiskPath(string $diskPath, string $disk = 'public'): void
    {
        $fullPath  = Storage::disk($disk)->path($diskPath);
        $thumbDiskPath = dirname($diskPath) . '/thumb/' . basename($diskPath);
        $thumbFullPath = Storage::disk($disk)->path($thumbDiskPath);

        if (file_exists($thumbFullPath)) {
            return;
        }

        $thumbDir = dirname($thumbFullPath);
        if (! is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        self::makeThumbnail($fullPath, $thumbFullPath);
    }

    /**
     * Generate a thumbnail from $src and write it to $dest using GD.
     */
    public static function makeThumbnail(string $src, string $dest): void
    {
        $info = getimagesize($src);

        if ($info === false) {
            throw new \RuntimeException('Cannot read image (corrupted or unsupported format)');
        }

        [$origW, $origH, $type] = $info;

        $source = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => imagecreatefrompng($src),
            IMAGETYPE_WEBP => imagecreatefromwebp($src),
            IMAGETYPE_GIF  => imagecreatefromgif($src),
            default        => throw new \RuntimeException("Unsupported image type: {$type}"),
        };

        // No upscaling
        if ($origW <= self::THUMB_WIDTH) {
            $newW = $origW;
            $newH = $origH;
        } else {
            $newW = self::THUMB_WIDTH;
            $newH = (int) round($origH * self::THUMB_WIDTH / $origW);
        }

        $thumb = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for PNG/GIF
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefilledrectangle($thumb, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($thumb, $dest, self::THUMB_QUALITY),
            IMAGETYPE_PNG  => imagepng($thumb, $dest, (int) round((100 - self::THUMB_QUALITY) / 10)),
            IMAGETYPE_WEBP => imagewebp($thumb, $dest, self::THUMB_QUALITY),
            IMAGETYPE_GIF  => imagegif($thumb, $dest),
        };

        imagedestroy($source);
        imagedestroy($thumb);
    }
}
