<?php

namespace App\Services\Support;

use App\Models\Store\StoreUser;
use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

final class SupportAccessService
{
    public const EMPLOYEE_PERMISSION = 'Technical Support';

    public const STORE_PERMISSION = 'Online Store Support';

    public function canAccess(Authenticatable $actor, SupportConversation $conversation): bool
    {
        if ($conversation->source === SupportConversation::SOURCE_ONLINE_STORE) {
            if ((int) $conversation->requester_user_id === (int) $actor->getAuthIdentifier()
                && ($actor instanceof StoreUser || strcasecmp((string) ($actor->type ?? ''), 'User') === 0)) {
                return ! (bool) ($actor->is_blocked ?? false)
                    && (! method_exists($actor, 'trashed') || ! $actor->trashed());
            }

            return $actor instanceof User && $this->canManageStore($actor);
        }

        if (! $actor instanceof User) {
            return false;
        }

        return $this->canManageEmployee($actor)
            || (int) ($actor->employee?->id ?? 0) === (int) $conversation->employee_id;
    }

    public function canManageInbox(Authenticatable $actor): bool
    {
        return $actor instanceof User
            && ($this->canManageEmployee($actor) || $this->canManageStore($actor));
    }

    public function canManageEmployee(User $user): bool
    {
        return $this->hasPermission($user, self::EMPLOYEE_PERMISSION);
    }

    public function canManageStore(User $user): bool
    {
        return $this->hasPermission($user, self::STORE_PERMISSION);
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if ($user->type === 'admin') {
            return true;
        }

        return (bool) $user->employee?->permissions()
            ->whereHas('permission', fn ($query) => $query->where('name_en', $permission))
            ->exists();
    }
}
