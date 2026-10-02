<?php

namespace App\Policies\OnlineStore;

use App\Models\User;
use Illuminate\Auth\Access\Response;

final class OnlineStorePolicy
{
    public function authorize(User $user, string $permission): Response
    {
        return $this->hasPermission($user, $permission)
            ? Response::allow()
            : Response::deny('This action requires an Online Store permission.');
    }

    public function authorizeResource(
        User $user,
        string $permission,
        bool $isAccessible,
        ?int $ownerUserId = null
    ): Response {
        $ownsResource = $user->type === 'admin'
            || $ownerUserId === null
            || $ownerUserId === (int) $user->getKey();

        if (! $isAccessible || ! $ownsResource || ! $this->hasPermission($user, $permission)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    private function hasPermission(User $user, string $permission): bool
    {
        return $user->type === 'admin' || $user->hasEmployeePermission($permission);
    }
}
