<?php

namespace Tests\Feature\OnlineStore;

use App\Mail\ResetPasswordMail;
use App\Models\PasswordResetCode;
use App\Services\OnlineStore\StorePasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use ReflectionMethod;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class StorePasswordResetSecurityTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as baseRefreshTestDatabase;
    }

    private const VERSION = [
        'app' => 'store',
        'platform' => 'android',
        'current_version' => '2.2.1',
        'current_build' => 10,
    ];

    protected function refreshTestDatabase(): void
    {
        $this->requireDisposableDatabase();
        if (filter_var(env('ONLINE_STORE_PREMIGRATED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->beginDatabaseTransaction();

            return;
        }
        $this->baseRefreshTestDatabase();
    }

    public function test_forgot_is_generic_and_never_returns_identity_or_otp(): void
    {
        Mail::fake();
        $user = OnlineStoreFixtureFactory::createStoreActor(['email' => 'reset-existing@example.invalid']);

        $existing = $this->postJson('/Auth/ForgotPassword', [...self::VERSION, 'Email' => $user->email])->assertOk();
        $missing = $this->postJson('/Auth/ForgotPassword', [...self::VERSION, 'Email' => 'reset-missing@example.invalid'])->assertOk();

        $this->assertSame($existing->json(), $missing->json());
        $existing->assertExactJson(['status' => 'success', 'message' => 'success']);
        $this->assertArrayNotHasKey('otp', $existing->json());
        $this->assertArrayNotHasKey('userId', $existing->json());
    }

    public function test_development_mode_can_return_the_real_issued_otp(): void
    {
        Mail::fake();
        config()->set('store.password_reset_expose_otp', true);
        $user = OnlineStoreFixtureFactory::createStoreActor([
            'email' => 'reset-development@example.invalid',
        ]);

        $response = $this->postJson('/Auth/ForgotPassword', [
            ...self::VERSION,
            'Email' => $user->email,
        ])->assertOk();

        $developmentOtp = (string) $response->json('developmentOtp');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $developmentOtp);
        Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use ($developmentOtp, $user) {
            return $mail->get_user_email === $user->email
                && (string) $mail->validToken === $developmentOtp;
        });
    }

    public function test_otp_is_hashed_expiring_account_bound_and_issues_an_opaque_proof(): void
    {
        Mail::fake();
        $first = OnlineStoreFixtureFactory::createStoreActor(['email' => 'reset-first@example.invalid']);
        $second = OnlineStoreFixtureFactory::createStoreActor(['email' => 'reset-second@example.invalid']);
        $otp = $this->requestOtp($first->email);
        $challenge = PasswordResetCode::query()->where('user_id', $first->id)->latest('id')->firstOrFail();

        $this->assertNotSame($otp, $challenge->token);
        $this->assertTrue(Hash::check($otp, $challenge->token));
        $this->assertTrue($challenge->expires_at->isFuture());

        $this->postJson('/Auth/VerifyForgotPasswordOtp', [...self::VERSION, 'Email' => $second->email, 'otp' => $otp])
            ->assertUnprocessable();
        $proof = $this->postJson('/Auth/VerifyForgotPasswordOtp', [...self::VERSION, 'Email' => $first->email, 'otp' => $otp])
            ->assertOk()->json('resetProof');

        $this->assertIsString($proof);
        $this->assertGreaterThanOrEqual(80, strlen($proof));
        $stored = DB::table('password_reset_tokens')->where('email', $first->email)->first();
        $this->assertNotSame($proof, $stored->token);
        $this->assertSame(hash('sha256', $proof), $stored->token);
        $this->postJson('/Auth/VerifyForgotPasswordOtp', [...self::VERSION, 'Email' => $first->email, 'otp' => $otp])
            ->assertUnprocessable();
    }

    public function test_forgot_requests_are_bounded_without_changing_the_generic_response(): void
    {
        Mail::fake();
        $user = OnlineStoreFixtureFactory::createStoreActor(['email' => 'bounded-existing@example.invalid']);
        $responses = collect();

        foreach (range(1, StorePasswordResetService::MAX_CHALLENGE_REQUESTS_PER_IDENTITY + 1) as $request) {
            $responses->push($this->postJson('/Auth/ForgotPassword', [
                ...self::VERSION, 'Email' => $user->email,
            ])->assertOk()->json());
        }

        $this->assertCount(1, $responses->unique(fn (array $response) => json_encode($response)));
        $this->assertSame(['status' => 'success', 'message' => 'success'], $responses->last());
        Mail::assertSentCount(StorePasswordResetService::MAX_CHALLENGE_REQUESTS_PER_IDENTITY);
        $this->assertSame(
            StorePasswordResetService::MAX_CHALLENGE_REQUESTS_PER_IDENTITY,
            PasswordResetCode::query()->where('user_id', $user->id)->count()
        );

        $missingResponses = collect();
        foreach (range(1, StorePasswordResetService::MAX_CHALLENGE_REQUESTS_PER_IDENTITY + 1) as $request) {
            $missingResponses->push($this->postJson('/Auth/ForgotPassword', [
                ...self::VERSION, 'Email' => 'bounded-missing@example.invalid',
            ])->assertOk()->json());
        }
        $this->assertSame($responses->all(), $missingResponses->all());
    }

    public function test_requesting_another_otp_does_not_reset_verification_attempts(): void
    {
        Mail::fake();
        $user = OnlineStoreFixtureFactory::createStoreActor(['email' => 'shared-attempts@example.invalid']);
        $this->requestOtp($user->email);
        $this->postJson('/Auth/VerifyForgotPasswordOtp', [...self::VERSION, 'Email' => $user->email, 'otp' => '000000'])
            ->assertUnprocessable();

        $latestOtp = $this->requestOtp($user->email);
        foreach (range(2, StorePasswordResetService::MAX_VERIFY_ATTEMPTS) as $attempt) {
            $this->postJson('/Auth/VerifyForgotPasswordOtp', [...self::VERSION, 'Email' => $user->email, 'otp' => '000000'])
                ->assertUnprocessable();
        }

        $this->postJson('/Auth/VerifyForgotPasswordOtp', [...self::VERSION, 'Email' => $user->email, 'otp' => $latestOtp])
            ->assertUnprocessable();
    }

    public function test_challenge_rate_limit_keys_hash_identity_and_ip(): void
    {
        $service = app(StorePasswordResetService::class);
        $method = new ReflectionMethod($service, 'challengeRequestKey');
        $email = 'private.identity@example.invalid';
        $ip = '203.0.113.41';
        $identityKey = $method->invoke($service, 'identity', $email);
        $ipKey = $method->invoke($service, 'ip', $ip);

        $this->assertStringNotContainsString($email, $identityKey);
        $this->assertStringNotContainsString($ip, $ipKey);
        $this->assertMatchesRegularExpression('/^store-password-reset:challenge:identity:[a-f0-9]{64}$/', $identityKey);
        $this->assertMatchesRegularExpression('/^store-password-reset:challenge:ip:[a-f0-9]{64}$/', $ipKey);

        $service->allowsChallengeRequest($email, $ip);
        $this->assertSame(1, RateLimiter::attempts($identityKey));
        $this->assertSame(1, RateLimiter::attempts($ipKey));
    }

    public function test_wrong_expired_and_attempt_bounded_otp_are_denied(): void
    {
        Mail::fake();
        $user = OnlineStoreFixtureFactory::createStoreActor(['email' => 'reset-attempts@example.invalid']);
        $otp = $this->requestOtp($user->email);

        foreach (range(1, StorePasswordResetService::MAX_VERIFY_ATTEMPTS) as $attempt) {
            $this->postJson('/Auth/VerifyForgotPasswordOtp', [...self::VERSION, 'Email' => $user->email, 'otp' => '000000'])
                ->assertUnprocessable();
        }
        $this->postJson('/Auth/VerifyForgotPasswordOtp', [...self::VERSION, 'Email' => $user->email, 'otp' => $otp])
            ->assertUnprocessable();

        $expiredUser = OnlineStoreFixtureFactory::createStoreActor(['email' => 'reset-expired@example.invalid']);
        $expiredOtp = $this->requestOtp($expiredUser->email);
        PasswordResetCode::query()->where('user_id', $expiredUser->id)->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/Auth/VerifyForgotPasswordOtp', [...self::VERSION, 'Email' => $expiredUser->email, 'otp' => $expiredOtp])
            ->assertUnprocessable();
    }

    public function test_reset_proof_is_account_bound_expiring_and_single_use_and_user_id_alone_is_rejected(): void
    {
        Mail::fake();
        $first = OnlineStoreFixtureFactory::createStoreActor(['email' => 'proof-first@example.invalid']);
        $second = OnlineStoreFixtureFactory::createStoreActor(['email' => 'proof-second@example.invalid']);
        $proof = $this->verifiedProof($first->email);
        $oldSecondPassword = $second->password;

        $this->patchJson('/Auth/ChangePasswordToForgot', [
            ...self::VERSION, 'userId' => $second->id, 'resetProof' => $proof,
            'newPassword' => 'new-secure-password', 'confirmPassword' => 'new-secure-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secure-password', $first->fresh()->password));
        $this->assertSame($oldSecondPassword, $second->fresh()->password);
        $this->patchJson('/Auth/ChangePasswordToForgot', [
            ...self::VERSION, 'resetProof' => $proof,
            'newPassword' => 'another-password', 'confirmPassword' => 'another-password',
        ])->assertUnprocessable();
        $this->patchJson('/Auth/ChangePasswordToForgot', [
            ...self::VERSION, 'userId' => $second->id,
            'newPassword' => 'another-password', 'confirmPassword' => 'another-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('resetProof');

        $expiredProof = $this->verifiedProof($second->email);
        DB::table('password_reset_tokens')->where('email', $second->email)->update([
            'created_at' => now()->subMinutes(StorePasswordResetService::PROOF_TTL_MINUTES + 1),
        ]);
        $this->patchJson('/Auth/ChangePasswordToForgot', [
            ...self::VERSION, 'resetProof' => $expiredProof,
            'newPassword' => 'expired-password', 'confirmPassword' => 'expired-password',
        ])->assertUnprocessable();
    }

    public function test_old_or_missing_client_metadata_is_upgrade_required_and_secrets_are_not_logged(): void
    {
        Mail::fake();
        Log::spy();
        $user = OnlineStoreFixtureFactory::createStoreActor(['email' => 'reset-log@example.invalid']);

        foreach ([
            ['Email' => $user->email],
            [...self::VERSION, 'current_build' => 9, 'Email' => $user->email],
        ] as $payload) {
            $this->postJson('/Auth/ForgotPassword', $payload)->assertStatus(426)->assertExactJson([
                'status' => 'upgrade_required',
                'message' => 'A Store app update is required to reset the password securely.',
                'minimum_build' => 10,
            ]);
        }
        $this->postJson('/Auth/VerifyForgotPasswordOtp', ['Email' => $user->email, 'otp' => '000000'])
            ->assertStatus(426)->assertJsonPath('status', 'upgrade_required');
        $this->patchJson('/Auth/ChangePasswordToForgot', [
            'resetProof' => str_repeat('x', 80),
            'newPassword' => 'missing-version-password',
            'confirmPassword' => 'missing-version-password',
        ])->assertStatus(426)->assertJsonPath('status', 'upgrade_required');

        $proof = $this->verifiedProof($user->email);
        $this->patchJson('/Auth/ChangePasswordToForgot', [
            ...self::VERSION, 'resetProof' => $proof,
            'newPassword' => 'log-safe-password', 'confirmPassword' => 'log-safe-password',
        ])->assertOk();
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    private function requestOtp(string $email): string
    {
        $this->postJson('/Auth/ForgotPassword', [...self::VERSION, 'Email' => $email])->assertOk();
        $otp = null;
        Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use ($email, &$otp) {
            if ($mail->get_user_email !== $email) {
                return false;
            }
            $otp = (string) $mail->validToken;

            return true;
        });

        $otp = (string) Mail::sent(ResetPasswordMail::class)->last()->validToken;

        return (string) $otp;
    }

    private function verifiedProof(string $email): string
    {
        $otp = $this->requestOtp($email);

        return (string) $this->postJson('/Auth/VerifyForgotPasswordOtp', [...self::VERSION, 'Email' => $email, 'otp' => $otp])
            ->assertOk()->json('resetProof');
    }
}
