<?php

namespace App\Services\OnlineStore;

use App\Models\Customer;
use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\SalesOrder;
use App\Models\Seller;
use App\Models\User;
use App\Support\OnlineStore\OnlineStoreValues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StoreIdentityService
{
    public function save(User $actor, User $storeUser, array $data, ?OnlineStoreAccountLink $link = null): OnlineStoreAccountLink
    {
        return DB::transaction(function () use ($actor, $storeUser, $data, $link) {
            $storeUser = User::withTrashed()->lockForUpdate()->findOrFail($storeUser->getKey());
            if ($storeUser->trashed() || $storeUser->is_blocked || strcasecmp((string) $storeUser->type, 'User') !== 0) {
                throw ValidationException::withMessages(['user_id' => ['The Store user is blocked or archived.']]);
            }

            $role = (string) ($data['role'] ?? $link?->role);
            if (! in_array($role, OnlineStoreValues::ACCOUNT_ROLES, true)) {
                throw ValidationException::withMessages(['role' => ['The selected account role is invalid.']]);
            }
            $partyId = (int) ($role === 'customer' ? ($data['customer_id'] ?? $link?->customer_id) : ($data['seller_id'] ?? $link?->seller_id));
            $party = ($role === 'customer' ? Customer::query() : Seller::query())->lockForUpdate()->find($partyId);
            if (! $party || (bool) ($party->is_canceled ?? false)) {
                throw ValidationException::withMessages([$role.'_id' => ['The selected party is unavailable.']]);
            }

            $conflict = OnlineStoreAccountLink::query()->where(function ($query) use ($storeUser, $role, $partyId) {
                $query->where(fn ($q) => $q->where('user_id', $storeUser->getKey())->where('role', $role))
                    ->orWhere($role === 'customer' ? 'customer_id' : 'seller_id', $partyId);
            });
            if ($link?->exists) {
                $conflict->where($link->getKeyName(), '<>', $link->getKey());
            }
            if ($conflict->exists()) {
                throw ValidationException::withMessages(['account_link' => ['The Store user role or selected party is already linked.']]);
            }

            $link ??= new OnlineStoreAccountLink;
            $before = $link->exists ? $link->getAttributes() : null;
            $link->forceFill([
                'user_id' => $storeUser->getKey(),
                'customer_id' => $role === 'customer' ? $partyId : null,
                'seller_id' => $role === 'seller' ? $partyId : null,
                'role' => $role,
                'account_source' => $data['account_source'] ?? $link->account_source ?? 'admin_app',
                'status' => $data['status'] ?? $link->status ?? 'pending',
                'linked_by' => $link->linked_by ?? $actor->getKey(),
            ]);
            if ($link->status === 'active') {
                $link->verified_by = $actor->getKey();
                $link->verified_at = now();
            } else {
                $link->verified_by = null;
                $link->verified_at = null;
            }
            $link->save();
            $this->audit($actor, $link, $before, $link->getAttributes());

            return $link->fresh(['user', 'customer', 'seller', 'linkedBy', 'verifiedBy']);
        });
    }

    public function activeLink(User $user, ?string $role = null): OnlineStoreAccountLink
    {
        $query = OnlineStoreAccountLink::query()->with(['customer', 'seller'])
            ->where('user_id', $user->getKey())->where('status', 'active')->whereNotNull('verified_at');
        if ($role !== null) {
            $query->where('role', $role);
        }
        $links = $query->get()->filter(function (OnlineStoreAccountLink $link) {
            $party = $link->party();

            return $party !== null && ! (bool) ($party->is_canceled ?? false);
        })->values();
        if ($links->count() !== 1) {
            throw ValidationException::withMessages(['account_role' => [$links->isEmpty()
                ? 'No active verified Store account link exists.'
                : 'Select an explicit account role for this checkout.']]);
        }

        return $links->first();
    }

    public function ownedOrders(User $user): Builder
    {
        $links = OnlineStoreAccountLink::query()->where('user_id', $user->getKey())
            ->where('status', 'active')->whereNotNull('verified_at')->get();
        $customerIds = $links->where('role', 'customer')->pluck('customer_id')->filter()->all();
        $sellerIds = $links->where('role', 'seller')->pluck('seller_id')->filter()->all();

        return SalesOrder::query()->where(function ($query) use ($customerIds, $sellerIds) {
            if ($customerIds !== []) {
                $query->orWhereIn('customer_id', $customerIds)
                    ->orWhere(fn ($q) => $q->where('partner_type', 'customer')->whereIn('partner_id', $customerIds));
            }
            if ($sellerIds !== []) {
                $query->orWhere(fn ($q) => $q->where('partner_type', 'seller')->whereIn('partner_id', $sellerIds));
            }
            if ($customerIds === [] && $sellerIds === []) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    public function ownedOrderOrFail(User $user, int $orderId): SalesOrder
    {
        return $this->ownedOrders($user)->whereKey($orderId)->firstOrFail();
    }

    private function audit(User $actor, OnlineStoreAccountLink $link, ?array $before, array $after): void
    {
        DB::table('online_store_audit_events')->insert([
            'actor_user_id' => $actor->getKey(), 'action' => $before ? 'updated' : 'linked',
            'entity_type' => 'account_link', 'entity_id' => $link->getKey(),
            'before_values' => $before ? json_encode($before) : null, 'after_values' => json_encode($after),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
