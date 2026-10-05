<?php

namespace Tests\Unit\OnlineStore;

use App\Services\OnlineStore\StorePasswordResetService;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class StorePasswordResetRateLimitTest extends TestCase
{
    public function test_identity_challenge_requests_are_bounded_independently(): void
    {
        $service = app(StorePasswordResetService::class);
        $identity = Str::uuid().'@example.invalid';
        $ip = '198.51.100.'.random_int(1, 200);

        foreach (range(1, StorePasswordResetService::MAX_CHALLENGE_REQUESTS_PER_IDENTITY) as $attempt) {
            $this->assertTrue($service->allowsChallengeRequest($identity, $ip));
        }
        $this->assertFalse($service->allowsChallengeRequest($identity, $ip));
    }

    public function test_ip_challenge_requests_are_bounded_across_distinct_identities(): void
    {
        $service = app(StorePasswordResetService::class);
        $ip = '203.0.113.'.random_int(1, 200);

        foreach (range(1, StorePasswordResetService::MAX_CHALLENGE_REQUESTS_PER_IP) as $attempt) {
            $this->assertTrue($service->allowsChallengeRequest(Str::uuid().'@example.invalid', $ip));
        }
        $this->assertFalse($service->allowsChallengeRequest(Str::uuid().'@example.invalid', $ip));
    }

    public function test_rate_limit_keys_never_contain_plaintext_identity_or_ip(): void
    {
        $service = app(StorePasswordResetService::class);
        $method = new ReflectionMethod($service, 'challengeRequestKey');
        $email = 'secret.identity@example.invalid';
        $ip = '192.0.2.87';

        $identityKey = $method->invoke($service, 'identity', $email);
        $ipKey = $method->invoke($service, 'ip', $ip);

        $this->assertStringNotContainsString($email, $identityKey);
        $this->assertStringNotContainsString($ip, $ipKey);
        $this->assertMatchesRegularExpression('/^store-password-reset:challenge:identity:[a-f0-9]{64}$/', $identityKey);
        $this->assertMatchesRegularExpression('/^store-password-reset:challenge:ip:[a-f0-9]{64}$/', $ipKey);
    }
}
