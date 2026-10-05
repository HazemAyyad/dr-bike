<?php

namespace Tests\Unit\OnlineStore;

use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Support\OnlineStore\OnlineStoreTargetRegistry;
use App\Support\OnlineStore\OnlineStoreValues;
use Database\Seeders\OnlineStorePermissionSeeder;
use PHPUnit\Framework\TestCase;

class OnlineStoreDomainBoundaryTest extends TestCase
{
    public function test_phase_two_does_not_create_a_parallel_order_or_authoritative_balance_domain(): void
    {
        $migrationSource = $this->phaseTwoMigrationSource();

        $this->assertStringNotContainsString("Schema::create('online_store_orders'", $migrationSource);
        $this->assertStringNotContainsString("'stock_balance'", $migrationSource);
        $this->assertStringNotContainsString("'debt_balance'", $migrationSource);
        $this->assertStringNotContainsString("'available_credit'", $migrationSource);
        $this->assertStringNotContainsString("'retail_price'", $migrationSource);
        $this->assertStringNotContainsString("'wholesale_price'", $migrationSource);
    }

    public function test_online_store_schema_does_not_repurpose_physical_store_sections(): void
    {
        $this->assertStringNotContainsString('store_sections', $this->phaseTwoMigrationSource());
    }

    public function test_listing_and_origin_values_are_bounded_to_the_approved_v1_contract(): void
    {
        $this->assertSame(['draft', 'ready', 'published', 'hidden'], OnlineStoreValues::LISTING_STATUSES);
        $this->assertSame(['admin', 'store'], OnlineStoreValues::ORDER_ORIGINS);
        $this->assertSame(['listing', 'category'], OnlineStoreValues::TARGET_TYPES);
    }

    public function test_typed_target_registry_rejects_arbitrary_types_and_enforces_section_compatibility(): void
    {
        $this->assertTrue(OnlineStoreTargetRegistry::sectionAcceptsTarget('categories', 'manual', 'category'));
        $this->assertFalse(OnlineStoreTargetRegistry::sectionAcceptsTarget('categories', 'manual', 'listing'));
        $this->assertTrue(OnlineStoreTargetRegistry::sectionAcceptsTarget('offers', 'manual', 'listing'));
        $this->assertFalse(OnlineStoreTargetRegistry::sectionAcceptsTarget('hero', 'dedicated_banners', 'listing'));
        $this->assertFalse(OnlineStoreTargetRegistry::sectionAcceptsTarget('maintenance', 'automatic', 'category'));

        $this->expectException(\InvalidArgumentException::class);
        OnlineStoreTargetRegistry::targetTable(\stdClass::class);
    }

    public function test_admin_boundary_declares_every_approved_permission_hook(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3).'/routes/api.php');

        $this->assertStringContainsString("Route::prefix('online-store')", $routes);
        $this->assertStringContainsString("'auth:sanctum', 'refresh.token.expiry'", $routes);

        foreach (OnlineStorePermissionSeeder::PERMISSIONS as $permission) {
            $this->assertStringContainsString("check.permission:{$permission}", $routes);
        }
    }

    public function test_shared_policy_keeps_admin_bypass_but_hides_inaccessible_resources(): void
    {
        $admin = new User;
        $admin->forceFill(['id' => 100, 'type' => 'admin']);
        $policy = new OnlineStorePolicy;

        $this->assertTrue($policy->authorize($admin, 'Online Store View')->allowed());
        $this->assertTrue($policy->authorizeResource($admin, 'Online Store View', true, 999)->allowed());

        $denied = $policy->authorizeResource($admin, 'Online Store View', false);
        $this->assertFalse($denied->allowed());
        $this->assertSame(404, $denied->status());
    }

    public function test_coupon_reservation_hook_precedes_sales_order_creation_effects(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $salesOrderService = file_get_contents($repositoryRoot.'/app/Services/SalesOrderService.php');
        $checkoutService = file_get_contents($repositoryRoot.'/app/Services/OnlineStore/OnlineStoreCheckoutService.php');

        $hook = strpos($salesOrderService, '$beforeCreationEffects($order);');
        $items = strpos($salesOrderService, '$this->syncItems($order');
        $stock = strpos($salesOrderService, '$this->applyUnconfirmedStockReservation(');
        $status = strpos($salesOrderService, '$this->logStatus($order, null');
        $notification = strpos($salesOrderService, '$this->notifications->notifyStatusChange(');

        $this->assertIsInt($hook);
        $this->assertIsInt($items);
        $this->assertIsInt($stock);
        $this->assertIsInt($status);
        $this->assertIsInt($notification);
        $this->assertLessThan($items, $hook);
        $this->assertLessThan($stock, $hook);
        $this->assertLessThan($status, $hook);
        $this->assertLessThan($notification, $hook);
        $this->assertStringContainsString("'before_creation_effects' => \$reserveCoupon", $checkoutService);
        $this->assertStringNotContainsString("\n        if (\$priced['coupon']) {\n            \$this->coupons->reserve", $checkoutService);
    }

    private function phaseTwoMigrationSource(): string
    {
        $source = '';
        $repositoryRoot = dirname(__DIR__, 3);
        foreach (glob($repositoryRoot.'/database/migrations/2026_10_01_00000*_*.php') ?: [] as $file) {
            $source .= file_get_contents($file);
        }

        return $source;
    }
}
