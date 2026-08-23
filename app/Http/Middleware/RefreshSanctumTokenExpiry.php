<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RefreshSanctumTokenExpiry
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            // Check if expired
            if ($token->expires_at && now()->greaterThan($token->expires_at)) {
                $token->delete(); // Optional: revoke token
                return response()->json([
                    'status'=>'error',
                    'message' => 'Token expired'], 200);
            }

            // Refresh expiry to 7 days from now
            $token->forceFill([
                'expires_at' => now()->addDays(7),
            ]);
            $token->save();
        }

        return $next($request);
    }
}
