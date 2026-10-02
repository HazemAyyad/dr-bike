<?php

namespace App\Http\Requests\OnlineStore;

use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;

trait AuthorizesOnlineStoreResource
{
    protected function authorizeOnlineStorePermission(string $permission): void
    {
        $user = $this->authenticatedOnlineStoreUser();
        app(OnlineStorePolicy::class)->authorize($user, $permission)->authorize();
    }

    protected function authorizeOnlineStoreResource(
        string $permission,
        bool $isAccessible,
        ?int $ownerUserId = null
    ): void {
        $user = $this->authenticatedOnlineStoreUser();
        app(OnlineStorePolicy::class)
            ->authorizeResource($user, $permission, $isAccessible, $ownerUserId)
            ->authorize();
    }

    private function authenticatedOnlineStoreUser(): User
    {
        $user = $this->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
