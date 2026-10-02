<?php

namespace App\Support\OnlineStore;

use InvalidArgumentException;

final class OnlineStoreTargetRegistry
{
    public const CONTEXT_PROMOTION = 'promotion';
    public const CONTEXT_COUPON = 'coupon';

    private const TARGET_TABLES = [
        'listing' => 'online_store_listings',
        'category' => 'online_store_categories',
    ];

    private const MANUAL_SECTION_TARGETS = [
        'categories' => ['category'],
        'best_sellers' => ['listing'],
        'recent' => ['listing'],
        'offers' => ['listing'],
        'custom' => ['listing'],
    ];

    public static function targetTable(string $targetType): string
    {
        return self::TARGET_TABLES[$targetType]
            ?? throw new InvalidArgumentException('Unsupported Online Store target type.');
    }

    public static function allowedTargetTypes(string $context): array
    {
        return match ($context) {
            self::CONTEXT_PROMOTION, self::CONTEXT_COUPON => OnlineStoreValues::TARGET_TYPES,
            default => throw new InvalidArgumentException('Unsupported Online Store target context.'),
        };
    }

    public static function allowedSectionTargetTypes(string $sectionType, string $selectionMode): array
    {
        if ($selectionMode !== 'manual') {
            return [];
        }

        return self::MANUAL_SECTION_TARGETS[$sectionType] ?? [];
    }

    public static function sectionAcceptsTarget(string $sectionType, string $selectionMode, string $targetType): bool
    {
        return in_array($targetType, self::allowedSectionTargetTypes($sectionType, $selectionMode), true);
    }

    private function __construct()
    {
    }
}
