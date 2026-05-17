<?php

namespace App\Services;

use App\Models\AdminDeviceToken;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class UserSessionManager
{
    /**
     * @return Collection<int, User>
     */
    public function listStaffUsers(?string $type = null, ?string $search = null): Collection
    {
        $allowed = config('user_sessions_manager.types', ['admin', 'employee']);

        $query = User::query()
            ->whereIn('type', $allowed)
            ->withCount([
                'activeSanctumTokens as active_sessions_count',
                'tokens as total_tokens_count',
                'adminDeviceTokens as admin_fcm_devices_count',
            ])
            ->withMax('tokens as latest_token_at', 'created_at')
            ->orderBy('type')
            ->orderBy('name');

        if ($type !== null && $type !== '' && in_array($type, $allowed, true)) {
            $query->where('type', $type);
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        return $query->get();
    }

    /**
     * @return array{user: User, tokens: Collection<int, PersonalAccessToken>}
     */
    public function userWithSessions(int $userId): array
    {
        $user = User::query()
            ->whereIn('type', config('user_sessions_manager.types', ['admin', 'employee']))
            ->withCount('adminDeviceTokens as admin_fcm_devices_count')
            ->findOrFail($userId);
        $tokens = $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        return ['user' => $user, 'tokens' => $tokens];
    }

    public function findStaffUser(int $userId): User
    {
        $allowed = config('user_sessions_manager.types', ['admin', 'employee']);

        return User::query()
            ->whereIn('type', $allowed)
            ->findOrFail($userId);
    }

    /**
     * @return array{users: int, tokens: int}
     */
    public function revokeAllStaffSessions(): array
    {
        $allowed = config('user_sessions_manager.types', ['admin', 'employee']);
        $users = User::query()->whereIn('type', $allowed)->get();
        $totalTokens = 0;

        foreach ($users as $user) {
            $totalTokens += $this->revokeAllSessions($user);
        }

        return [
            'users' => $users->count(),
            'tokens' => $totalTokens,
        ];
    }

    public function revokeAllSessions(User $user): int
    {
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        $user->forceFill(['fcm_token' => null])->save();

        if ($user->type === 'admin') {
            AdminDeviceToken::query()->where('user_id', $user->id)->delete();
        }

        return $count;
    }

    public function revokeSession(int $tokenId): bool
    {
        $token = PersonalAccessToken::query()->findOrFail($tokenId);

        if ($token->tokenable_type !== User::class) {
            return false;
        }

        $this->findStaffUser((int) $token->tokenable_id);
        $token->delete();

        return true;
    }

    public function changePassword(User $user, string $plainPassword): void
    {
        $user->forceFill([
            'password' => Hash::make($plainPassword),
        ])->save();
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            'admin' => 'مدير',
            'employee' => 'موظف',
            default => $type,
        };
    }

    /**
     * @return array{has_fcm: bool, has_user_token: bool, admin_devices: int, token_preview: ?string, label: string}
     */
    public function fcmStatusForUser(User $user): array
    {
        $userToken = trim((string) ($user->fcm_token ?? ''));
        $isNoTokenPlaceholder = $userToken === 'no_token';
        $hasUserToken = $userToken !== '' && ! $isNoTokenPlaceholder;

        $adminDevices = 0;
        if ($user->type === 'admin') {
            $adminDevices = (int) ($user->admin_fcm_devices_count
                ?? $user->adminDeviceTokens()->count());
        }

        $hasFcm = $hasUserToken || $adminDevices > 0;

        $tokenPreview = $hasUserToken ? \Illuminate\Support\Str::limit($userToken, 32) : null;

        $label = match (true) {
            $hasUserToken && $user->type === 'employee' => 'FCM ✓ (موظف)',
            $hasUserToken && $user->type === 'admin' => 'FCM ✓ (users.fcm_token)',
            ! $hasUserToken && $adminDevices > 0 => "FCM ✓ ({$adminDevices} جهاز أدمن)",
            $isNoTokenPlaceholder => 'no_token (بدون إشعارات حقيقية)',
            default => 'بدون FCM',
        };

        return [
            'has_fcm' => $hasFcm,
            'has_user_token' => $hasUserToken,
            'is_no_token_placeholder' => $isNoTokenPlaceholder,
            'admin_devices' => $adminDevices,
            'token_preview' => $tokenPreview,
            'label' => $label,
        ];
    }
}
