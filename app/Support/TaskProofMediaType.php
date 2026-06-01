<?php

namespace App\Support;

final class TaskProofMediaType
{
    public const NONE = 'none';
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const BOTH = 'both';

    public static function normalize(?string $value, bool $required = false): string
    {
        $value = strtolower(trim((string) $value));

        if (in_array($value, [self::NONE, self::IMAGE, self::VIDEO, self::BOTH], true)) {
            if (! $required) {
                return self::NONE;
            }

            return $value === self::NONE ? self::BOTH : $value;
        }

        return $required ? self::BOTH : self::NONE;
    }

    public static function fromRequestValue(mixed $value, bool $required): string
    {
        return self::normalize(is_string($value) ? $value : null, $required);
    }
}
