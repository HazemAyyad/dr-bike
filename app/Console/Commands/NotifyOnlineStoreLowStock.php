<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Services\AdminNotificationService;
use App\Services\OnlineStore\OnlineStoreSettingsService;
use App\Services\OnlineStore\StoreAvailabilityService;
use Illuminate\Console\Command;

class NotifyOnlineStoreLowStock extends Command
{
    protected $signature = 'online-store:notify-low-stock';

    protected $description = 'Notify admins about published Online Store products at or below the configured stock threshold';

    public function handle(
        OnlineStoreSettingsService $settingsService,
        StoreAvailabilityService $availability,
        AdminNotificationService $notifications,
    ): int {
        $settings = $settingsService->current(create: false);
        if (! $settings) {
            $this->info('Online Store settings do not exist yet.');

            return self::SUCCESS;
        }

        $threshold = max(0, (int) $settings->low_stock_threshold);
        $lowListingIds = [];
        $created = 0;
        OnlineStoreListing::query()
            ->where('status', 'published')
            ->with('product')
            ->orderBy('id')
            ->chunkById(100, function ($listings) use ($availability, $notifications, $threshold, &$lowListingIds, &$created): void {
                foreach ($listings as $listing) {
                    $snapshot = $availability->resolve($listing->product, $listing);
                    $available = (int) ($snapshot['available_qty'] ?? 0);
                    if ($available > $threshold) {
                        continue;
                    }

                    $lowListingIds[] = (int) $listing->id;
                    $alreadyNotified = AdminNotification::query()
                        ->where('type', AdminNotificationService::TYPE_ONLINE_STORE_LOW_STOCK)
                        ->where('related_type', 'online_store_listing')
                        ->where('related_id', $listing->id)
                        ->where(function ($query) {
                            $query->where('is_read', false)
                                ->orWhere('created_at', '>=', now()->subDay());
                        })->exists();
                    if ($alreadyNotified) {
                        continue;
                    }

                    $name = trim((string) ($listing->product?->nameAr ?: $listing->product?->nameEng ?: 'منتج المتجر'));
                    $notifications->create(
                        AdminNotificationService::TYPE_ONLINE_STORE_LOW_STOCK,
                        'تنبيه مخزون المتجر',
                        "{$name}: المتبقي {$available} والحد المضبوط {$threshold}",
                        [
                            'listing_id' => (string) $listing->id,
                            'product_id' => (string) $listing->product_id,
                            'product_name' => $name,
                            'available_qty' => (string) $available,
                            'low_stock_threshold' => (string) $threshold,
                        ],
                        relatedType: 'online_store_listing',
                        relatedId: (int) $listing->id,
                    );
                    $created++;
                }
            });

        AdminNotification::query()
            ->where('type', AdminNotificationService::TYPE_ONLINE_STORE_LOW_STOCK)
            ->where('related_type', 'online_store_listing')
            ->where('is_read', false)
            ->when($lowListingIds !== [], fn ($query) => $query->whereNotIn('related_id', $lowListingIds))
            ->when($lowListingIds === [], fn ($query) => $query)
            ->update(['is_read' => true, 'read_at' => now()]);

        $this->info("Created {$created} low-stock notification(s).");

        return self::SUCCESS;
    }
}
