<?php

namespace Tests\Unit;

use App\Services\FirebaseService;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class FirebaseServiceConfigurationTest extends TestCase
{
    public function test_credentials_path_comes_from_cached_configuration(): void
    {
        config()->set('services.firebase.credentials', 'storage/firebase/firebase_credentials.json');

        $method = new ReflectionMethod(FirebaseService::class, 'envCredentialsPath');
        $method->setAccessible(true);

        $this->assertSame(
            'storage/firebase/firebase_credentials.json',
            $method->invoke(new FirebaseService())
        );
    }

    /** @dataProvider staleProjectTokenErrors */
    public function test_project_mismatch_errors_invalidate_stale_tokens(string $message): void
    {
        $method = new ReflectionMethod(FirebaseService::class, 'isInvalidTokenError');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(new FirebaseService(), new RuntimeException($message)));
    }

    public static function staleProjectTokenErrors(): array
    {
        return [
            ['Sender ID mismatch'],
            ['messaging/sender-id-mismatch'],
            ['The credential used to authenticate this SDK does not have permission: mismatched-credential'],
        ];
    }
}
