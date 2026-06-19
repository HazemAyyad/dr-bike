<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Support\SalesOrderMediaCategory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SalesOrderMediaRequirementService
{
    /**
     * @return list<string>
     */
    public function requiredBeforeReady(): array
    {
        return [SalesOrderMediaCategory::ITEMS_GROUP];
    }

    /**
     * @return list<string>
     */
    public function requiredBeforeHandover(): array
    {
        return [
            SalesOrderMediaCategory::ITEMS_GROUP,
            SalesOrderMediaCategory::PACKAGED,
        ];
    }

    public function hasCategory(SalesOrder $order, string $category): bool
    {
        if (! Schema::hasColumn('sales_order_media', 'category')) {
            return $category === SalesOrderMediaCategory::GENERAL
                ? $order->media()->exists()
                : false;
        }

        return $order->media()
            ->where('category', $category)
            ->exists();
    }

    /**
     * @param  list<string>  $categories
     */
    public function assertCategoriesPresent(SalesOrder $order, array $categories): void
    {
        if (! Schema::hasColumn('sales_order_media', 'category')) {
            return;
        }

        $order->loadMissing('media');

        foreach ($categories as $category) {
            if ($this->hasCategory($order, $category)) {
                continue;
            }

            throw ValidationException::withMessages([
                'media' => [__('messages.sales_order_media_required_'.$category)],
            ]);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildRequirementsPayload(SalesOrder $order): array
    {
        $order->loadMissing('media');

        if (! Schema::hasColumn('sales_order_media', 'category')) {
            return [];
        }

        $present = $order->media->pluck('category')->unique()->all();

        $defs = [
            SalesOrderMediaCategory::ITEMS_GROUP => [
                'required_for' => ['mark_ready', 'handover'],
                'optional' => false,
            ],
            SalesOrderMediaCategory::PACKAGED => [
                'required_for' => ['handover'],
                'optional' => false,
            ],
            SalesOrderMediaCategory::TESTING => [
                'required_for' => [],
                'optional' => true,
            ],
            SalesOrderMediaCategory::DOCUMENT => [
                'required_for' => [],
                'optional' => true,
                'deferred_allowed' => true,
            ],
        ];

        $out = [];
        foreach ($defs as $category => $meta) {
            $out[$category] = array_merge($meta, [
                'category' => $category,
                'label' => __('messages.sales_order_media_category_'.$category),
                'satisfied' => in_array($category, $present, true),
            ]);
        }

        return $out;
    }
}
