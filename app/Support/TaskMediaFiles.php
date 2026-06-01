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

    public static function hasRequiredProof(mixed $files, ?string $proofMediaType, bool $required): bool
    {
        if (! $required) {
            return true;
        }

        return match (TaskProofMediaType::normalize($proofMediaType, true)) {
            TaskProofMediaType::IMAGE => self::hasImageProof($files),
            TaskProofMediaType::VIDEO => self::hasVideoProof($files),
            default => self::hasProof($files),
        };
    }

    private static function hasImageProof(mixed $files): bool
    {
        foreach (self::flatten($files) as $item) {
            if (is_string($item) && trim($item) !== '' && ! self::isVideoFilename($item)) {
                return true;
            }
        }

        return false;
    }

    private static function hasVideoProof(mixed $files): bool
    {
        foreach (self::flatten($files) as $item) {
            if (is_string($item) && self::isVideoFilename($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, mixed>
     */
    private static function flatten(mixed $files): array
    {
        if ($files === null) {
            return [];
        }

        if (! is_array($files)) {
            return [$files];
        }

        $items = [];
        foreach ($files as $item) {
            if (is_array($item)) {
                array_push($items, ...self::flatten($item));
            } else {
                $items[] = $item;
            }
        }

        return $items;
    }
}
