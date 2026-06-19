<?php

namespace App\Support;

final class SalesOrderMediaCategory
{
    public const GENERAL = 'general';

    public const ITEMS_GROUP = 'items_group';

    public const PACKAGED = 'packaged';

    public const TESTING = 'testing';

    public const DOCUMENT = 'document';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::GENERAL,
            self::ITEMS_GROUP,
            self::PACKAGED,
            self::TESTING,
            self::DOCUMENT,
        ];
    }

    public static function isValid(?string $value): bool
    {
        return in_array((string) $value, self::all(), true);
    }

    public static function normalize(?string $value): string
    {
        return self::isValid($value) ? (string) $value : self::GENERAL;
    }
}
