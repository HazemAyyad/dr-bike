<?php

namespace App\Support;

use Laravel\Sanctum\PersonalAccessToken;

final class SanctumTokenHelper
{
    public static function isExpired(?PersonalAccessToken $token): bool
    {
        if (! $token) {
            return true;
        }

        return $token->expires_at !== null && now()->greaterThan($token->expires_at);
    }
}
