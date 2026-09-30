<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaUploadService
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/tiff' => 'tiff',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'video/x-msvideo' => 'avi',
        'video/x-matroska' => 'mkv',
        'video/x-ms-wmv' => 'wmv',
        'video/3gpp' => '3gp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function storePublic(UploadedFile $file, string $directory, array $allowedMimes): string
    {
        if (! $file->isValid() || ! is_readable($file->getPathname())) {
            throw ValidationException::withMessages(['file' => ['تعذر قراءة الملف المرفوع.']]);
        }

        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages(['file' => ['نوع الملف غير مسموح.']]);
        }

        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            throw ValidationException::withMessages(['file' => ['تعذر تحديد نوع الملف بأمان.']]);
        }

        $name = Str::uuid().'.'.$extension;
        $target = public_path(trim($directory, '/\\'));
        File::ensureDirectoryExists($target);
        $file->move($target, $name);

        return $name;
    }

    public static function imageMimes(bool $allowHeic = false): array
    {
        $mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff', 'image/avif', 'image/svg+xml'];

        return $allowHeic ? [...$mimes, 'image/heic', 'image/heif'] : $mimes;
    }

    public static function videoMimes(): array
    {
        return ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-msvideo', 'video/x-matroska', 'video/x-ms-wmv', 'video/3gpp'];
    }

    public static function detectedExtension(UploadedFile $file): ?string
    {
        return self::MIME_EXTENSIONS[strtolower((string) $file->getMimeType())] ?? null;
    }
}
