<?php

namespace Tests\Unit;

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
}
