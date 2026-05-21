<?php

namespace App\Support;

final class TaskMediaFiles
{
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v', '3gp'];

    public static function isVideoFilename(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, self::VIDEO_EXTENSIONS, true);
    }

    public static function hasProof(mixed $files): bool
    {
        if (EmployeeProofImages::has($files)) {
            return true;
        }

        if (! is_array($files)) {
            return false;
        }

        foreach ($files as $item) {
            if (is_string($item) && self::isVideoFilename($item)) {
                return true;
            }
        }

        return false;
    }
}
