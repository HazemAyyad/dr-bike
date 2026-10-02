<?php

namespace App\Support\OnlineStore;

use Illuminate\Validation\Rule;

final class OnlineStoreValues
{
    public const LISTING_STATUSES = ['draft', 'ready', 'published', 'hidden'];
    public const READINESS_STATES = ['incomplete', 'complete'];
    public const MEDIA_SOURCE_TYPES = ['normal_image', 'image3d', 'view_image', 'variant', 'store_specific'];
    public const SECTION_TYPES = ['hero', 'categories', 'best_sellers', 'recent', 'maintenance', 'offers', 'custom'];
    public const SECTION_MODES = ['manual', 'automatic', 'dedicated_banners'];
    public const TARGET_TYPES = ['listing', 'category'];
    public const DISCOUNT_TYPES = ['percentage', 'fixed'];
    public const DISCOUNT_SCOPES = ['global', 'targeted'];
    public const PRICE_CONTEXTS = ['retail', 'wholesale', 'both'];
    public const ACCOUNT_TYPES = ['customer', 'seller', 'both'];
    public const ACCOUNT_ROLES = ['customer', 'seller'];
    public const ACCOUNT_SOURCES = ['store_app', 'admin_app', 'import'];
    public const ACCOUNT_STATUSES = ['pending', 'active', 'suspended'];
    public const REVIEW_STATUSES = ['pending', 'published', 'rejected'];
    public const REDEMPTION_STATUSES = ['reserved', 'applied', 'released'];
    public const ORDER_ORIGINS = ['admin', 'store'];
    public const BANNER_ACTION_TYPES = ['listing', 'category', 'promotion', 'url', 'none'];
    public const OUT_OF_STOCK_BEHAVIORS = ['visible_non_purchasable'];

    public const AUDIT_ENTITY_TYPES = [
        'listing', 'category', 'media_presentation', 'promotion', 'coupon', 'home_section',
        'banner', 'account_link', 'credit_policy', 'review', 'settings',
    ];

    public const AUDIT_ACTION_TYPES = [
        'created', 'updated', 'deleted', 'status_changed', 'published', 'hidden',
        'activated', 'deactivated', 'linked', 'unlinked', 'moderated',
    ];

    public static function rule(array $allowed): Rule
    {
        return Rule::in($allowed);
    }

    public static function listingStatusRule(): Rule
    {
        return self::rule(self::LISTING_STATUSES);
    }

    public static function sectionTypeRule(): Rule
    {
        return self::rule(self::SECTION_TYPES);
    }

    public static function sectionModeRule(): Rule
    {
        return self::rule(self::SECTION_MODES);
    }

    public static function targetTypeRule(): Rule
    {
        return self::rule(self::TARGET_TYPES);
    }

    public static function discountTypeRule(): Rule
    {
        return self::rule(self::DISCOUNT_TYPES);
    }

    public static function discountScopeRule(): Rule
    {
        return self::rule(self::DISCOUNT_SCOPES);
    }

    public static function accountRoleRule(): Rule
    {
        return self::rule(self::ACCOUNT_ROLES);
    }

    public static function accountStatusRule(): Rule
    {
        return self::rule(self::ACCOUNT_STATUSES);
    }

    public static function reviewStatusRule(): Rule
    {
        return self::rule(self::REVIEW_STATUSES);
    }

    public static function originRule(): Rule
    {
        return self::rule(self::ORDER_ORIGINS);
    }

    public static function auditEntityTypeRule(): Rule
    {
        return self::rule(self::AUDIT_ENTITY_TYPES);
    }

    public static function auditActionTypeRule(): Rule
    {
        return self::rule(self::AUDIT_ACTION_TYPES);
    }

    private function __construct()
    {
    }
}
