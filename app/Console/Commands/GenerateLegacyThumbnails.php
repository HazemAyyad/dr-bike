<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateLegacyThumbnails extends Command
{
    protected $signature = 'images:generate-legacy-thumbs';
    protected $description = 'Generate 400px thumbnails for legacy images in public/Images/Items (uses GD)';

    private const THUMB_WIDTH   = 400;
    private const THUMB_QUALITY = 75;

    public function handle(): int
    {
        $sourceDir = public_path('Images/Items');
        $thumbDir  = $sourceDir . DIRECTORY_SEPARATOR . 'thumb';

        if (! is_dir($sourceDir)) {
            $this->error("Source directory not found: {$sourceDir}");
            return self::FAILURE;
        }

        if (! is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        $files = array_filter(
            glob($sourceDir . DIRECTORY_SEPARATOR . '*'),
            fn ($f) => is_file($f) && $this->isSupportedImage($f)
        );

        $total = count($files);

        if ($total === 0) {
            $this->info('No images found.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} image(s). Generating thumbnails...");

        $generated = 0;
        $skipped   = 0;

        foreach ($files as $path) {
            $filename  = basename($path);
            $thumbPath = $thumbDir . DIRECTORY_SEPARATOR . $filename;

            if (file_exists($thumbPath)) {
                $this->line("  <fg=gray>skip</>  {$filename}");
                $skipped++;
                continue;
            }

            try {
                $this->makeThumbnail($path, $thumbPath);
                $this->line("  <fg=green>done</>  {$filename}");
                $generated++;
            } catch (\Throwable $e) {
                $this->line("  <fg=red>fail</>  {$filename}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done. Generated: {$generated} | Skipped: {$skipped} | Total: {$total}");

        return self::SUCCESS;
    }

    private function makeThumbnail(string $src, string $dest): void
    {
        [$origW, $origH, $type] = getimagesize($src);

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

    private function isSupportedImage(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif']);
    }
}
