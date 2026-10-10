<?php

namespace App\Services\OnlineStore;

use App\Models\Store\StoreUser;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class StoreActivityTracker
{
    public function actor(Request $request): ?StoreUser
    {
        if ($request->attributes->get('store_user_actor_resolved') === true) {
            $resolved = $request->attributes->get('store_user_actor');

            return $resolved instanceof StoreUser ? $resolved : null;
        }

        $token = trim((string) $request->bearerToken());
        if ($token === '') {
            return $this->remember($request, null);
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (! $accessToken || ($accessToken->expires_at && $accessToken->expires_at->isPast())) {
            return $this->remember($request, null);
        }
        $expiration = config('sanctum.expiration');
        if ($expiration !== null && $accessToken->created_at?->lte(now()->subMinutes((int) $expiration))) {
            return $this->remember($request, null);
        }
        if (! $accessToken->tokenable instanceof StoreUser) {
            return $this->remember($request, null);
        }

        $user = $accessToken->tokenable;
        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subSeconds(30))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $this->remember($request, $user);
    }

    private function remember(Request $request, ?StoreUser $user): ?StoreUser
    {
        $request->attributes->set('store_user_actor_resolved', true);
        $request->attributes->set('store_user_actor', $user);

        return $user;
    }
}
