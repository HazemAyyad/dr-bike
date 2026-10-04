<?php

namespace App\Services\OnlineStore;

use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StorePasswordResetService
{
    public const OTP_TTL_MINUTES = 15;

    public const PROOF_TTL_MINUTES = 15;

    public const MAX_VERIFY_ATTEMPTS = 5;

    public function createChallenge(User $user): string
    {
        $otp = (string) random_int(100000, 999999);

        DB::transaction(function () use ($user, $otp) {
            PasswordResetCode::query()
                ->where('user_id', $user->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            PasswordResetCode::query()->create([
                'user_id' => $user->getKey(),
                'email' => $this->normalizeIdentity((string) $user->email),
                'token' => Hash::make($otp),
                'delivery_method' => 'email',
                'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                'used_at' => null,
            ]);
        });

        RateLimiter::clear($this->attemptKey((string) $user->email));

        return $otp;
    }

    public function verify(string $identity, string $otp): string
    {
        $identity = $this->normalizeIdentity($identity);
        PasswordResetCode::query()
            ->where('email', $identity)
            ->whereNull('used_at')
            ->where('expires_at', '<', now())
            ->update(['used_at' => now()]);
        $attemptKey = $this->attemptKey($identity);
        if (RateLimiter::tooManyAttempts($attemptKey, self::MAX_VERIFY_ATTEMPTS)) {
            throw ValidationException::withMessages(['otp' => ['The verification code is invalid or expired.']]);
        }
        RateLimiter::hit($attemptKey, self::OTP_TTL_MINUTES * 60);

        return DB::transaction(function () use ($identity, $otp, $attemptKey) {
            $challenge = PasswordResetCode::query()
                ->where('email', $identity)
                ->whereNull('used_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $challenge || ! $challenge->expires_at || $challenge->expires_at->isPast()
                || ! Hash::check($otp, (string) $challenge->token)) {
                throw ValidationException::withMessages(['otp' => ['The verification code is invalid or expired.']]);
            }

            $user = User::query()->whereKey($challenge->user_id)->where('email', $identity)->first();
            if (! $user || $user->trashed() || $user->is_blocked || strcasecmp((string) $user->type, 'User') !== 0) {
                throw ValidationException::withMessages(['otp' => ['The verification code is invalid or expired.']]);
            }

            $challenge->forceFill(['used_at' => now()])->save();
            $proof = Str::random(80);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $identity],
                ['token' => hash('sha256', $proof), 'created_at' => now()]
            );
            RateLimiter::clear($attemptKey);

            return $proof;
        }, 3);
    }

    public function reset(string $proof, string $password): User
    {
        DB::table('password_reset_tokens')
            ->where('created_at', '<', now()->subMinutes(self::PROOF_TTL_MINUTES))
            ->delete();

        return DB::transaction(function () use ($proof, $password) {
            $record = DB::table('password_reset_tokens')
                ->where('token', hash('sha256', $proof))
                ->lockForUpdate()
                ->first();
            if (! $record) {
                throw ValidationException::withMessages(['resetProof' => ['The reset proof is invalid or expired.']]);
            }

            $user = User::query()->where('email', $record->email)->lockForUpdate()->first();
            if (! $user || $user->trashed() || $user->is_blocked || strcasecmp((string) $user->type, 'User') !== 0) {
                throw ValidationException::withMessages(['resetProof' => ['The reset proof is invalid or expired.']]);
            }

            $user->forceFill(['password' => Hash::make($password)])->save();
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $record->email)->delete();
            PasswordResetCode::query()->where('user_id', $user->getKey())->whereNull('used_at')->update(['used_at' => now()]);

            return $user;
        }, 3);
    }

    private function normalizeIdentity(string $identity): string
    {
        return mb_strtolower(trim($identity));
    }

    private function attemptKey(string $identity): string
    {
        return 'store-password-reset:'.hash('sha256', $this->normalizeIdentity($identity));
    }
}
