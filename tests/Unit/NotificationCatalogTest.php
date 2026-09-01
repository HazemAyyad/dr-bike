<?php

namespace Tests\Unit;

use App\Enums\SalesOrderStatus;
use App\Services\SalesOrderNotificationService;
use App\Support\NotificationCatalog;
use PHPUnit\Framework\TestCase;

class NotificationCatalogTest extends TestCase
{
    public function test_every_notification_type_uses_a_known_bundled_sound(): void
    {
        $sounds = NotificationCatalog::bundledSounds();

        foreach (NotificationCatalog::types() as $type => $definition) {
            $this->assertArrayHasKey(
                $definition['sound'],
                $sounds,
                "Notification type {$type} references an unknown sound."
            );
        }
    }

    public function test_sensitive_notification_defaults_to_security_category(): void
    {
        $otp = NotificationCatalog::types()['password_reset_otp'];

        $this->assertTrue($otp['sensitive']);
        $this->assertSame('security', $otp['category']);
        $this->assertSame('critical', $otp['priority']);
    }

    public function test_library_sounds_use_ios_notification_compatible_wav_files(): void
    {
        $library = array_filter(
            NotificationCatalog::bundledSounds(),
            fn (array $sound): bool => ($sound['category'] ?? null) === 'library'
        );

        $this->assertCount(10, $library);
        foreach ($library as $key => $sound) {
            $this->assertStringEndsWith('.wav', $sound['ios'], "{$key} must use WAV on iOS.");
            $this->assertStringNotContainsString('.', $sound['android'], "{$key} Android resource must omit extension.");
        }
    }

    public function test_incoming_customer_messages_have_independent_sound_policies(): void
    {
        $types = NotificationCatalog::types();

        $this->assertSame('messages', $types['whatsapp_message_received']['category']);
        $this->assertSame('library_message_pop', $types['whatsapp_message_received']['sound']);
        $this->assertSame('messages', $types['social_message_received']['category']);
        $this->assertSame('library_clear_announce', $types['social_message_received']['sound']);
    }

    public function test_every_sales_order_stage_has_an_independent_policy(): void
    {
        $types = NotificationCatalog::types();

        foreach (SalesOrderStatus::cases() as $status) {
            $type = SalesOrderNotificationService::typeForStatus($status->value);

            $this->assertArrayHasKey($type, $types, "Missing notification policy for {$status->value}.");
            $this->assertSame('sales_orders', $types[$type]['category']);
        }
    }

    public function test_shiply_order_events_are_available_in_the_catalog(): void
    {
        $types = NotificationCatalog::types();

        $this->assertArrayHasKey(SalesOrderNotificationService::TYPE_SHIPLY_HANDOVER, $types);
        $this->assertArrayHasKey(SalesOrderNotificationService::TYPE_SHIPLY_DELIVERED, $types);
        $this->assertArrayHasKey(SalesOrderNotificationService::TYPE_SHIPLY_STATUS, $types);
    }
}
