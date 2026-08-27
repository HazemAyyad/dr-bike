<?php

namespace App\Console\Commands;

use App\Models\Paper;
use App\Models\Picture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Schema;

class AuditOfficialPapersMedia extends Command
{
    protected $signature = 'official-papers:audit-media';
    protected $description = 'Create a read-only inventory of official-paper records and media';

    public function handle(): int
    {
        $tables = ['treasuries', 'file_boxes', 'files', 'papers', 'pictures'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = Schema::hasTable($table) ? \DB::table($table)->count() : null;
        }

        $paperMedia = [];
        if (Schema::hasTable('papers')) {
            Paper::query()->orderBy('id')->each(function (Paper $paper) use (&$paperMedia) {
                foreach ($paper->img ?? [] as $file) {
                    $name = basename((string) $file);
                    $path = public_path('Papers/'.$name);
                    $paperMedia[] = $this->mediaRow('paper', $paper->id, $name, $path);
                }
            });
        }

        $pictureMedia = [];
        if (Schema::hasTable('pictures')) {
            Picture::query()->orderBy('id')->each(function (Picture $picture) use (&$pictureMedia) {
                if ($picture->file) {
                    $name = basename((string) $picture->file);
                    $pictureMedia[] = $this->mediaRow(
                        'picture',
                        $picture->id,
                        $name,
                        public_path('Pictures/'.$name)
                    );
                }
            });
        }

        $paperDiskFiles = $this->diskFiles(public_path('Papers'));
        $pictureDiskFiles = $this->diskFiles(public_path('Pictures'));
        $referencedPaperNames = collect($paperMedia)->pluck('filename');
        $referencedPictureNames = collect($pictureMedia)->pluck('filename');

        $report = [
            'generated_at' => now()->toIso8601String(),
            'mode' => 'read_only',
            'counts' => $counts,
            'media_summary' => [
                'paper_files' => count($paperMedia),
                'picture_files' => count($pictureMedia),
                'missing_paper_files' => collect($paperMedia)->where('exists', false)->count(),
                'missing_picture_files' => collect($pictureMedia)->where('exists', false)->count(),
                'paper_files_on_disk' => count($paperDiskFiles),
                'picture_files_on_disk' => count($pictureDiskFiles),
                'unlinked_paper_files' => collect($paperDiskFiles)->whereNotIn('filename', $referencedPaperNames)->count(),
                'unlinked_picture_files' => collect($pictureDiskFiles)->whereNotIn('filename', $referencedPictureNames)->count(),
            ],
            'paper_media' => $paperMedia,
            'picture_media' => $pictureMedia,
            'paper_disk_files' => $paperDiskFiles,
            'picture_disk_files' => $pictureDiskFiles,
        ];

        $directory = storage_path('app/audits/official-papers');
        FileFacade::ensureDirectoryExists($directory);
        $path = $directory.'/official-papers-audit-'.now()->format('Ymd-His').'.json';
        FileFacade::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Official-paper audit created: '.$path);
        $this->line(json_encode($report['media_summary'], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function diskFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        return collect(FileFacade::files($directory))->map(function ($file) {
            return [
                'filename' => $file->getFilename(),
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getPathname()),
            ];
        })->values()->all();
    }

    private function mediaRow(string $type, int $recordId, string $name, string $path): array
    {
        $exists = is_file($path);

        return [
            'type' => $type,
            'record_id' => $recordId,
            'filename' => $name,
            'exists' => $exists,
            'size_bytes' => $exists ? filesize($path) : null,
            'sha256' => $exists ? hash_file('sha256', $path) : null,
        ];
    }
}
