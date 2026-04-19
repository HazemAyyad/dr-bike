<?php

namespace App\Console\Commands;

use App\Helpers\ThumbnailHelper;
use Illuminate\Console\Command;

class GenerateLegacyThumbnails extends Command
{
    protected $signature = 'images:generate-legacy-thumbs';
    protected $description = 'Generate 400px thumbnails for legacy images in public/Images/Items (uses GD)';

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
            fn ($f) => is_file($f) && in_array(
                strtolower(pathinfo($f, PATHINFO_EXTENSION)),
                ['jpg', 'jpeg', 'png', 'webp', 'gif']
            )
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
                ThumbnailHelper::makeThumbnail($path, $thumbPath);
                $this->line("  <fg=green>done</>  {$filename}");
                $generated++;
            } catch (\Throwable $e) {
                $this->line("  <fg=gray>skip</>  {$filename} (unreadable: {$e->getMessage()})");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Done. Generated: {$generated} | Skipped: {$skipped} | Total: {$total}");

        return self::SUCCESS;
    }
}
