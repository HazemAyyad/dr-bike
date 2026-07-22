<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DatabaseBackupController extends Controller
{
    public function index(Request $request)
    {
        try {
            $path = $this->backupPath();
            File::ensureDirectoryExists($path, 0755, true);

            $files = collect(File::files($path))
                ->filter(fn ($file) => in_array(strtolower($file->getExtension()), ['sql', 'txt'], true))
                ->sortByDesc(fn ($file) => $file->getMTime())
                ->values()
                ->map(fn ($file) => $this->formatBackupFile($file->getFilename(), $file->getPathname()))
                ->values();

            return response()->json([
                'status' => 'success',
                'backup_path' => $path,
                'keep_days' => (int) config('database_backup.keep_days', 4),
                'schedule_human' => 'كل ساعة',
                'backups' => $files,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('database_backups.index_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function download(string $filename)
    {
        try {
            $safeFilename = basename($filename);
            if ($safeFilename !== $filename) {
                abort(404);
            }

            $path = $this->backupPath();
            $fullPath = $path.DIRECTORY_SEPARATOR.$safeFilename;
            $realBase = realpath($path);
            $realFile = realpath($fullPath);

            if ($realBase === false || $realFile === false || ! str_starts_with($realFile, $realBase)) {
                abort(404);
            }

            if (str_contains($safeFilename, '-in-progress.')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'لا يمكن تحميل نسخة ما زالت قيد الإنشاء.',
                ], 200);
            }

            return response()->download($realFile, $safeFilename);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('database_backups.download_failed', [
                'filename' => $filename,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function backupPath(): string
    {
        return rtrim((string) config('database_backup.path'), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBackupFile(string $filename, string $fullPath): array
    {
        $status = $this->statusFromFilename($filename);
        $lastModified = File::lastModified($fullPath);

        return [
            'filename' => $filename,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'size_bytes' => File::size($fullPath),
            'size_human' => $this->humanFileSize(File::size($fullPath)),
            'created_at' => date('Y-m-d H:i:s', $lastModified),
            'download_url' => url('/api/admin/database-backups/'.rawurlencode($filename).'/download'),
            'can_download' => $status !== 'in_progress',
        ];
    }

    private function statusFromFilename(string $filename): string
    {
        if (str_ends_with($filename, '-complete.sql')) {
            return 'complete';
        }

        if (str_ends_with($filename, '-failed.txt')) {
            return 'failed';
        }

        if (str_ends_with($filename, '-in-progress.sql')) {
            return 'in_progress';
        }

        return str_ends_with($filename, '.sql') ? 'complete' : 'unknown';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'complete' => 'ناجحة',
            'failed' => 'فشلت',
            'in_progress' => 'قيد الإنشاء',
            default => 'غير محددة',
        };
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format($size, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }
}
