<?php

namespace Tests\Feature\OnlineStore;

use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\OnlineStore\OnlineStoreAuditEvent;
use App\Models\OnlineStore\OnlineStoreReview;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Services\OnlineStore\OnlineStoreReviewService;
use App\Services\OnlineStore\StoreIdentityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class OnlineStoreReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_rating_state_and_ownership_are_server_enforced(): void
    {
        [$user, $customer] = $this->linkedCustomer();
        $product = OnlineStoreFixtureFactory::createProduct();
        $service = app(OnlineStoreReviewService::class);

        foreach ([0, 6] as $invalidRating) {
            try {
                $service->submit($user, $product, ['rating' => $invalidRating, 'comment' => 'fixture']);
                $this->fail('Ratings outside 1..5 must fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('rating', $exception->errors());
            }
        }

        $review = $service->submit($user, $product, [
            'rating' => 5,
            'comment' => 'fixture review',
            'customer_id' => $customer->id + 100,
            'user_id' => $user->id + 100,
            'is_verified_purchase' => true,
            'status' => OnlineStoreReview::STATUS_PUBLISHED,
        ]);

        $this->assertSame($customer->id, $review->customer_id);
        $this->assertSame($user->id, $review->user_id);
        $this->assertSame(OnlineStoreReview::STATUS_PENDING, $review->status);
        $this->assertFalse($review->is_verified_purchase);

        $sellerOnly = OnlineStoreFixtureFactory::createStoreActor();
        app(StoreIdentityService::class)->save(
            OnlineStoreFixtureFactory::createAdminActor(),
            $sellerOnly,
            ['role' => 'seller', 'seller_id' => OnlineStoreFixtureFactory::createSeller()->id, 'account_source' => 'admin_app', 'status' => 'active']
        );
        $this->expectException(ValidationException::class);
        $service->submit($sellerOnly, $product, ['rating' => 4, 'comment' => 'seller-only']);
    }

    public function test_verified_purchase_requires_owned_eligible_order_and_product_item(): void
    {
        [$user, $customer] = $this->linkedCustomer();
        $product = OnlineStoreFixtureFactory::createProduct();
        $otherProduct = OnlineStoreFixtureFactory::createProduct();
        $service = app(OnlineStoreReviewService::class);

        $wrongProductOrder = $this->order($customer->id, $user->id, 'delivered');
        $this->item($wrongProductOrder, $otherProduct->id, 1, 1, 0);
        $this->assertFalse($service->submit($user, $product, ['rating' => 4])->is_verified_purchase);

        $eligible = $this->order($customer->id, $user->id, 'delivered');
        $this->item($eligible, $product->id, 2, 2, 0);
        $review = $service->submit($user, $product, ['rating' => 5, 'is_verified_purchase' => false]);
        $this->assertTrue($review->is_verified_purchase);
        $this->assertSame($eligible->id, $review->sales_order_id);

        foreach (['canceled', 'returned', 'alternative_return', 'unconfirmed'] as $status) {
            $anotherProduct = OnlineStoreFixtureFactory::createProduct();
            $order = $this->order($customer->id, $user->id, $status);
            $this->item($order, $anotherProduct->id, 1, 1, 0);
            $this->assertFalse($service->submit($user, $anotherProduct, ['rating' => 3])->is_verified_purchase);
        }

        $fullyReturnedProduct = OnlineStoreFixtureFactory::createProduct();
        $partiallyReturnedOrder = $this->order($customer->id, $user->id, 'partial_return');
        $this->item($partiallyReturnedOrder, $fullyReturnedProduct->id, 1, 1, 1);
        $this->assertFalse($service->submit($user, $fullyReturnedProduct, ['rating' => 3])->is_verified_purchase);
    }

    public function test_cross_account_update_is_404_and_archived_product_is_invalid(): void
    {
        [$owner] = $this->linkedCustomer();
        [$other] = $this->linkedCustomer();
        $product = OnlineStoreFixtureFactory::createProduct();
        $review = app(OnlineStoreReviewService::class)->submit($owner, $product, ['rating' => 4]);

        try {
            app(OnlineStoreReviewService::class)->updateOwned($other, $review->id, ['rating' => 1]);
            $this->fail('A foreign review must not be discoverable.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $product->delete();
        $this->expectException(ValidationException::class);
        app(OnlineStoreReviewService::class)->submit($owner, $product, ['rating' => 3]);
    }

    public function test_moderation_requires_exact_permission_and_records_audit(): void
    {
        [$owner] = $this->linkedCustomer();
        $review = app(OnlineStoreReviewService::class)->submit(
            $owner,
            OnlineStoreFixtureFactory::createProduct(),
            ['rating' => 5, 'comment' => 'moderate me']
        );

        $denied = OnlineStoreFixtureFactory::createStoreActor(['type' => 'employee']);
        Sanctum::actingAs($denied);
        $this->withoutMiddleware(\App\Http\Middleware\RefreshSanctumTokenExpiry::class);
        $this->postJson('/api/online-store/reviews/'.$review->id.'/moderate', ['status' => 'published'])->assertNotFound();

        $employee = OnlineStoreFixtureFactory::createStoreActor(['type' => 'employee']);
        $details = EmployeeDetail::query()->forceCreate(['user_id' => $employee->id]);
        $permission = Permission::query()->firstOrCreate(
            ['name_en' => 'Online Store Reviews Manage'],
            ['name' => 'Online Store Reviews Manage']
        );
        EmployeePermission::query()->forceCreate(['employee_id' => $details->id, 'permission_id' => $permission->id]);
        Sanctum::actingAs($employee);

        $this->postJson('/api/online-store/reviews/'.$review->id.'/moderate', [
            'status' => 'published',
            'reason' => 'fixture approval',
        ])->assertOk()->assertJsonPath('data.status', 'published');

        $review->refresh();
        $this->assertSame($employee->id, $review->moderated_by);
        $this->assertNotNull($review->moderated_at);
        $this->assertDatabaseHas('online_store_audit_events', [
            'actor_user_id' => $employee->id,
            'entity_type' => 'review',
            'entity_id' => $review->id,
            'action' => 'moderated',
        ]);
        $audit = OnlineStoreAuditEvent::query()->where('entity_type', 'review')->where('entity_id', $review->id)->latest('id')->firstOrFail();
        $this->assertSame('pending', $audit->before_values['status']);
        $this->assertSame('published', $audit->after_values['status']);
        $this->assertArrayNotHasKey('comment', $audit->after_values);

        $rejected = app(OnlineStoreReviewService::class)->submit(
            $owner,
            OnlineStoreFixtureFactory::createProduct(),
            ['rating' => 2, 'comment' => 'reject fixture']
        );
        $this->postJson('/api/online-store/reviews/'.$rejected->id.'/moderate', [
            'status' => 'rejected',
            'reason' => 'fixture rejection',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_verified_purchase_is_revoked_after_reversal_or_source_order_deletion(): void
    {
        [$user, $customer] = $this->linkedCustomer();
        $product = OnlineStoreFixtureFactory::createProduct();
        $order = $this->order($customer->id, $user->id, 'delivered');
        $this->item($order, $product->id, 1, 1, 0);
        $service = app(OnlineStoreReviewService::class);
        $review = $service->submit($user, $product, ['rating' => 5]);
        $this->assertTrue($review->is_verified_purchase);

        $order->update(['status' => 'returned']);
        $this->assertFalse($service->refreshVerifiedPurchase($review->fresh())->is_verified_purchase);

        $order->update(['status' => 'delivered']);
        $this->assertTrue($service->refreshVerifiedPurchase($review->fresh())->is_verified_purchase);
        $order->delete();
        $refreshed = $service->refreshVerifiedPurchase($review->fresh());
        $this->assertNull($refreshed->sales_order_id);
        $this->assertFalse($refreshed->is_verified_purchase);
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Customer} */
    private function linkedCustomer(): array
    {
        $user = OnlineStoreFixtureFactory::createStoreActor();
        $customer = OnlineStoreFixtureFactory::createCustomer();
        app(StoreIdentityService::class)->save(
            OnlineStoreFixtureFactory::createAdminActor(),
            $user,
            ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']
        );

        return [$user, $customer];
    }

    private function order(int $customerId, int $originUserId, string $status): SalesOrder
    {
        return SalesOrder::query()->forceCreate([
            'customer_id' => $customerId,
            'partner_type' => 'customer',
            'partner_id' => $customerId,
            'status' => $status,
            'origin' => 'store',
            'origin_user_id' => $originUserId,
            'client_request_id' => 'review-'.uniqid('', true),
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    private function item(SalesOrder $order, int $productId, int $quantity, int $delivered, int $returned): SalesOrderItem
    {
        return SalesOrderItem::query()->forceCreate([
            'sales_order_id' => $order->id,
            'product_id' => $productId,
            'quantity' => $quantity,
            'delivered_qty' => $delivered,
            'returned_qty' => $returned,
            'unit_price' => 100,
            'line_total' => 100 * $quantity,
            'is_hidden' => false,
        ]);
    }
}
