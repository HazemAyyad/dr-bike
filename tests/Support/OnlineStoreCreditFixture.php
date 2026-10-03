<?php

namespace Tests\Support;

use App\Models\NormalImageProduct;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Services\OnlineStore\StoreCreditService;
use App\Services\OnlineStore\StoreIdentityService;
use Illuminate\Support\Facades\DB;

final class OnlineStoreCreditFixture
{
    public static function create(string $role = 'customer', float $price = 2000, int $stock = 10, ?float $limit = 2000, bool $eligible = true, ?string $expiresAt = null, string $currency = 'ILS'): array
    {
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        $actor = OnlineStoreFixtureFactory::createStoreActor();
        $party = $role === 'seller'
            ? OnlineStoreFixtureFactory::createSeller()
            : OnlineStoreFixtureFactory::createCustomer();
        $link = app(StoreIdentityService::class)->save($admin, $actor, [
            'role' => $role,
            $role.'_id' => $party->getKey(),
            'account_source' => 'admin_app',
            'status' => 'active',
        ]);
        $policy = app(StoreCreditService::class)->savePolicy($admin, $link, [
            'is_eligible' => $eligible,
            'credit_limit' => $limit,
            'currency' => $currency,
            'expires_at' => $expiresAt,
        ]);
        $product = OnlineStoreFixtureFactory::createProduct([
            'descriptionEng' => 'Credit fixture product',
            'normailPrice' => $price,
            'wholesalePrice' => $price,
            'stock' => $stock,
        ]);
        $listing = OnlineStoreListing::query()->forceCreate([
            'product_id' => $product->getKey(),
            'status' => 'published',
            'readiness_state' => 'complete',
        ]);
        $image = NormalImageProduct::query()->forceCreate([
            'id' => self::fixtureId(),
            'itemId' => $product->getKey(),
            'imageUrl' => 'fixtures/online-store-credit.jpg',
        ]);
        $categoryId = self::fixtureId();
        DB::table('online_store_categories')->insert([
            'id' => $categoryId,
            'name_translations' => json_encode(['en' => 'Credit fixture']),
            'is_active' => true,
            'show_on_home' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('online_store_category_listing')->insert([
            'online_store_category_id' => $categoryId,
            'online_store_listing_id' => $listing->getKey(),
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('online_store_media_presentations')->insert([
            'online_store_listing_id' => $listing->getKey(),
            'source_type' => 'normal_image',
            'source_id' => $image->getKey(),
            'is_main' => true,
            'is_visible' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'actor', 'party', 'link', 'policy', 'product', 'listing');
    }

    public static function nativePayload(int $listingId, string $requestId, string $paymentType = 'mixed', float $paidAmount = 500, string $role = 'customer'): array
    {
        return [
            'client_request_id' => $requestId,
            'account_role' => $role,
            'payment_type' => $paymentType,
            'payment_amount' => $paidAmount,
            'items' => [['listing_id' => $listingId, 'quantity' => 1]],
        ];
    }

    private static function fixtureId(): int
    {
        return random_int(1_000_000_000, 1_400_000_000);
    }
}
