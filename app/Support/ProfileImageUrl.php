<?php

namespace App\Support;

final class ProfileImageUrl
{
    public static function resolve(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/')
            .'/storage/'.ltrim($path, '/');
    }
}
