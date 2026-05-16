<?php

namespace App\Services;

use App\Models\AdminDeviceToken;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class UserSessionManager
{
    public function activeTokensQuery()
    {
        return fn ($query) => $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * @return Collection<int, User>
     */
    public function listStaffUsers(?string $type = null, ?string $search = null): Collection
    {
        $allowed = config('user_sessions_manager.types', ['admin', 'employee']);

        $query = User::query()
            ->whereIn('type', $allowed)
            ->withCount([
                'tokens as active_sessions_count' => $this->activeTokensQuery(),
                'tokens as total_tokens_count',
            ])
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
        $user = $this->findStaffUser($userId);
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
}
