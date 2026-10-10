<?php

namespace Tests\Unit;

use App\Models\AdminNotification;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\OnlineStore\OnlineStoreAuditService;
use App\Services\OnlineStore\OnlineStoreNotificationService;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\SalesOrderNotificationService;
use App\Services\SalesOrderShiplyTrackingService;
use Mockery;
use Tests\TestCase;

class SalesOrderCustomerImageNotificationTest extends TestCase
{
    public function test_store_order_notification_carries_the_customer_image_url(): void
    {
        config(['app.url' => 'https://dr-bike.example']);

        $order = new SalesOrder([
            'serial_number' => 'SO-55',
            'customer_name' => 'عميل المتجر',
        ]);
        $order->forceFill([
            'id' => 55,
            'origin' => SalesOrder::ORIGIN_STORE,
            'origin_user_id' => 9,
        ]);
        $user = new User;
        $user->forceFill([
            'id' => 9,
            'profile_image_path' => 'profile/customer.jpg',
        ]);
        $order->setRelation('originUser', $user);

        $adminNotifications = Mockery::mock(AdminNotificationService::class);
        $adminNotifications->shouldReceive('create')
            ->once()
            ->withArgs(function (...$arguments): bool {
                $data = $arguments[3] ?? [];

                return ($data['customer_image_url'] ?? null)
                    === 'https://dr-bike.example/storage/profile/customer.jpg';
            })
            ->andReturn(new AdminNotification);

        $storeNotifications = new OnlineStoreNotificationService(
            $adminNotifications,
            new StoreIdentityService(new OnlineStoreAuditService),
        );

        $service = new SalesOrderNotificationService(
            $adminNotifications,
            Mockery::mock(SalesOrderShiplyTrackingService::class),
            $storeNotifications,
        );

        $service->notifyStatusChange($order, null, 'customer_image_contract');
    }
}
