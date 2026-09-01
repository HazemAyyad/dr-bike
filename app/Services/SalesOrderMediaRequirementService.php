<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\AppSetting;
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
        return $this->requiredFor('mark_ready');
    }

    /**
     * @return list<string>
     */
    public function requiredBeforeHandover(): array
    {
        return $this->requiredFor('handover');
    }

    /** @return array<string, array<string, bool>> */
    public function settings(): array
    {
        $defaults = [
            'mark_ready' => [
                SalesOrderMediaCategory::ITEMS_GROUP => true,
                SalesOrderMediaCategory::PACKAGED => false,
                SalesOrderMediaCategory::TESTING => false,
                SalesOrderMediaCategory::DOCUMENT => false,
            ],
            'handover' => [
                SalesOrderMediaCategory::ITEMS_GROUP => true,
                SalesOrderMediaCategory::PACKAGED => true,
                SalesOrderMediaCategory::TESTING => false,
                SalesOrderMediaCategory::DOCUMENT => false,
            ],
        ];
        $raw = json_decode((string) AppSetting::get(
            AppSetting::KEY_SALES_ORDER_MEDIA_REQUIREMENTS_JSON,
            '{}'
        ), true);
        if (! is_array($raw)) return $defaults;
        foreach ($defaults as $stage => $categories) {
            foreach ($categories as $category => $default) {
                if (isset($raw[$stage]) && array_key_exists($category, $raw[$stage])) {
                    $defaults[$stage][$category] = (bool) $raw[$stage][$category];
                }
            }
        }
        return $defaults;
    }

    /** @param array<string, array<string, mixed>> $settings */
    public function updateSettings(array $settings): void
    {
        $current = $this->settings();
        foreach ($current as $stage => $categories) {
            foreach ($categories as $category => $enabled) {
                if (isset($settings[$stage]) && array_key_exists($category, $settings[$stage])) {
                    $current[$stage][$category] = (bool) $settings[$stage][$category];
                }
            }
        }
        AppSetting::set(
            AppSetting::KEY_SALES_ORDER_MEDIA_REQUIREMENTS_JSON,
            json_encode($current, JSON_UNESCAPED_UNICODE)
        );
    }

    /** @return list<string> */
    private function requiredFor(string $stage): array
    {
        return collect($this->settings()[$stage] ?? [])
            ->filter(fn ($enabled) => (bool) $enabled)
            ->keys()
            ->values()
            ->all();
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

        $configured = $this->settings();
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
            $requiredFor = collect($configured)
                ->filter(fn ($categories) => (bool) ($categories[$category] ?? false))
                ->keys()
                ->values()
                ->all();
            if ($requiredFor === []) {
                continue;
            }
            $out[$category] = array_merge($meta, [
                'required_for' => $requiredFor,
                'optional' => false,
                'category' => $category,
                'label' => __('messages.sales_order_media_category_'.$category),
                'satisfied' => in_array($category, $present, true),
            ]);
        }

        return $out;
    }
}
