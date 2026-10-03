<?php

namespace Tests\Feature\OnlineStore;

use App\Models\AdminNotification;
use App\Models\OnlineStore\OnlineStorePromotion;
use App\Models\SalesOrder;
use App\Services\FirebaseService;
use App\Services\OnlineStore\OnlineStoreNotificationService;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\SalesOrderNotificationService;
use App\Support\NotificationCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class OnlineStoreNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_order_lifecycle_targets_only_the_owned_eligible_store_account(): void
    {
        [$user, $customer] = $this->linkedCustomer(['fcm_token' => 'fixture-token']);
        [$otherUser] = $this->linkedCustomer(['fcm_token' => 'other-token']);
        $order = SalesOrder::query()->forceCreate([
            'customer_id' => $customer->id,
            'partner_type' => 'customer',
            'partner_id' => $customer->id,
            'status' => 'confirmed',
            'origin' => 'store',
            'origin_user_id' => $user->id,
            'client_request_id' => 'notification-owned-order',
        ]);

        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('sendToTokenQuietly')->once()->withArgs(function ($token, $title, $body, $data) use ($order) {
            $this->assertSame('fixture-token', $token);
            $this->assertNotSame('', $title);
            $this->assertNotSame('', $body);
            $this->assertSame('order', $data['destination_type']);
            $this->assertSame((string) $order->id, $data['destination_id']);
            $this->assertSame((string) $order->id, $data['order_id']);
            $this->assertSame('confirmed', $data['order_status']);
            foreach (['debt_balance', 'credit_limit', 'phone', 'address', 'access_token', 'otp', 'payment_credentials'] as $sensitive) {
                $this->assertArrayNotHasKey($sensitive, $data);
            }

            return true;
        })->andReturn(['name' => 'fixture-message']);
        $this->app->instance(FirebaseService::class, $firebase);

        app(SalesOrderNotificationService::class)->notifyStatusChange($order, 'unconfirmed', 'confirmed');

        $customerNotification = AdminNotification::query()
            ->where('type', OnlineStoreNotificationService::TYPE_ORDER_STATUS)
            ->where('recipient_user_id', $user->id)
            ->firstOrFail();
        $this->assertSame('order', $customerNotification->data['destination_type']);
        $this->assertDatabaseMissing('admin_notifications', [
            'type' => OnlineStoreNotificationService::TYPE_ORDER_STATUS,
            'recipient_user_id' => $otherUser->id,
        ]);
    }

    public function test_blocked_or_suspended_users_receive_no_order_or_marketing_notification(): void
    {
        [$blocked, $customer, $link] = $this->linkedCustomer();
        $blocked->update(['is_blocked' => true]);
        $order = SalesOrder::query()->forceCreate([
            'customer_id' => $customer->id,
            'partner_type' => 'customer',
            'partner_id' => $customer->id,
            'status' => 'delivered',
            'origin' => 'store',
            'origin_user_id' => $blocked->id,
            'client_request_id' => 'blocked-notification',
        ]);
        $service = app(OnlineStoreNotificationService::class);
        $this->assertNull($service->notifyOrderStatus($order, 'delivered'));

        $blocked->update(['is_blocked' => false]);
        $link->update(['status' => 'suspended', 'verified_at' => null]);
        $promotion = $this->activePromotion();
        $this->assertNull($service->notifyPromotion($blocked->fresh(), $promotion, CarbonImmutable::parse('2026-10-03 10:00:00')));
        $this->assertDatabaseMissing('admin_notifications', ['recipient_user_id' => $blocked->id]);
    }

    public function test_locale_fallback_controlled_templates_typed_destination_and_sensitive_exclusion(): void
    {
        [$user] = $this->linkedCustomer(['ui_preferences' => ['locale' => 'unsupported'], 'fcm_token' => null]);
        $promotion = $this->activePromotion();
        $notification = app(OnlineStoreNotificationService::class)->notifyPromotion(
            $user,
            $promotion,
            CarbonImmutable::parse('2026-10-03 10:00:00')
        );

        $this->assertNotNull($notification);
        $this->assertSame('ar', $notification->data['locale']);
        $this->assertSame('promotion', $notification->data['destination_type']);
        $this->assertSame((string) $promotion->id, $notification->data['destination_id']);
        $this->assertArrayHasKey(OnlineStoreNotificationService::TYPE_ORDER_STATUS, NotificationCatalog::types());
        $this->assertArrayHasKey(OnlineStoreNotificationService::TYPE_MARKETING_PROMOTION, NotificationCatalog::types());
        $payload = app(\App\Services\AdminNotificationService::class)->buildFcmDataPayload($notification);
        foreach (['debt_balance', 'credit_limit', 'phone', 'address', 'access_token', 'otp', 'payment_credentials'] as $sensitive) {
            $this->assertArrayNotHasKey($sensitive, $payload);
        }
    }

    public function test_marketing_requires_current_promotion_and_active_verified_recipient(): void
    {
        [$eligible] = $this->linkedCustomer();
        $service = app(OnlineStoreNotificationService::class);
        $at = CarbonImmutable::parse('2026-10-03 10:00:00');

        $inactive = $this->activePromotion(['is_active' => false]);
        $expired = $this->activePromotion(['ends_at' => $at->subSecond()]);
        $future = $this->activePromotion(['starts_at' => $at->addSecond()]);
        $this->assertNull($service->notifyPromotion($eligible, $inactive, $at));
        $this->assertNull($service->notifyPromotion($eligible, $expired, $at));
        $this->assertNull($service->notifyPromotion($eligible, $future, $at));

        $accepted = $service->notifyPromotion($eligible, $this->activePromotion(), $at);
        $this->assertNotNull($accepted);
        $this->assertSame($eligible->id, $accepted->recipient_user_id);
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Customer, 2: \App\Models\OnlineStore\OnlineStoreAccountLink} */
    private function linkedCustomer(array $userOverrides = []): array
    {
        $user = OnlineStoreFixtureFactory::createStoreActor($userOverrides);
        $customer = OnlineStoreFixtureFactory::createCustomer();
        $link = app(StoreIdentityService::class)->save(
            OnlineStoreFixtureFactory::createAdminActor(),
            $user,
            ['role' => 'customer', 'customer_id' => $customer->id, 'account_source' => 'admin_app', 'status' => 'active']
        );

        return [$user, $customer, $link];
    }

    private function activePromotion(array $overrides = []): OnlineStorePromotion
    {
        return OnlineStorePromotion::query()->forceCreate(array_merge([
            'name' => 'Controlled fixture promotion',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'applies_to' => 'both',
            'scope' => 'global',
            'starts_at' => '2026-10-03 09:00:00',
            'ends_at' => '2026-10-03 11:00:00',
            'is_active' => true,
            'priority' => 1,
        ], $overrides));
    }
}
