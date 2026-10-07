<?php

namespace App\Services\OnlineStore;

use App\Models\Customer;
use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\Store\StoreUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StoreCustomerOnboardingService
{
    public function ensureCustomerAccount(User|StoreUser $storeUser): OnlineStoreAccountLink
    {
        return DB::transaction(function () use ($storeUser) {
            $user = User::query()->lockForUpdate()->findOrFail($storeUser->getKey());
            $existing = OnlineStoreAccountLink::query()
                ->where('user_id', $user->getKey())
                ->where('role', 'customer')
                ->first();
            if ($existing) {
                return $existing;
            }

            $customer = Customer::query()->create([
                'name' => (string) ($user->name ?: $user->email),
                'phone' => $user->phone,
                'sub_phone' => $user->sub_phone,
                'address' => $user->address,
                'type' => 'customer',
                'is_canceled' => false,
            ]);
            $link = new OnlineStoreAccountLink;
            $link->forceFill([
                'user_id' => $user->getKey(),
                'customer_id' => $customer->getKey(),
                'seller_id' => null,
                'role' => 'customer',
                'account_source' => 'store_app',
                'status' => 'active',
                'linked_by' => null,
                'verified_by' => null,
                'verified_at' => now(),
            ]);
            $link->save();
            app(OnlineStoreAuditService::class)->record(
                $user,
                'linked',
                'account_link',
                (int) $link->getKey(),
                null,
                $link->getAttributes(),
            );

            return $link;
        }, 3);
    }
}
