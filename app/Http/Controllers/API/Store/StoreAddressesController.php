<?php

namespace App\Http\Controllers\API\Store;

use App\Models\Customer;
use App\Models\PartnerAddress;
use App\Models\User;
use App\Services\OnlineStore\StoreCustomerOnboardingService;
use App\Services\OnlineStore\StoreIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreAddressesController extends StoreBaseController
{
    public function index(
        Request $request,
        StoreCustomerOnboardingService $onboarding,
        StoreIdentityService $identity,
    ) {
        [$actor, $customer] = $this->customer($request, $onboarding, $identity);

        return response()->json([
            'data' => $customer->addresses()
                ->orderByDesc('is_default')
                ->orderByDesc('id')
                ->get()
                ->map(fn (PartnerAddress $address) => $this->payload($address))
                ->values(),
            'meta' => ['user_id' => (int) $actor->getKey()],
        ]);
    }

    public function store(
        Request $request,
        StoreCustomerOnboardingService $onboarding,
        StoreIdentityService $identity,
    ) {
        [$actor, $customer] = $this->customer($request, $onboarding, $identity);
        $data = $this->validated($request);

        $address = DB::transaction(function () use ($actor, $customer, $data) {
            $makeDefault = (bool) ($data['is_default'] ?? false) || ! $customer->addresses()->exists();
            if ($makeDefault) {
                $customer->addresses()->update(['is_default' => false]);
            }

            $address = $customer->addresses()->create(array_merge($data, [
                'is_default' => $makeDefault,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]));
            if ($makeDefault) {
                $this->syncProfileAddress($actor, $address);
            }

            return $address;
        });

        return response()->json(['data' => $this->payload($address)], 201);
    }

    public function update(
        Request $request,
        StoreCustomerOnboardingService $onboarding,
        StoreIdentityService $identity,
    ) {
        [$actor, $customer] = $this->customer($request, $onboarding, $identity);
        $data = $this->validated($request, true);
        $address = $customer->addresses()->findOrFail($request->integer('address_id'));
        unset($data['address_id']);
        if ($address->is_default && array_key_exists('is_default', $data) && ! $data['is_default']) {
            $data['is_default'] = true;
        }

        DB::transaction(function () use ($actor, $customer, $address, $data) {
            if ($data['is_default'] ?? false) {
                $customer->addresses()->whereKeyNot($address->getKey())->update(['is_default' => false]);
            }
            $address->update(array_merge($data, ['updated_by' => $actor->getKey()]));
            if ($address->fresh()->is_default) {
                $this->syncProfileAddress($actor, $address->fresh());
            }
        });

        return response()->json(['data' => $this->payload($address->fresh())]);
    }

    public function destroy(
        Request $request,
        StoreCustomerOnboardingService $onboarding,
        StoreIdentityService $identity,
    ) {
        [$actor, $customer] = $this->customer($request, $onboarding, $identity);
        $address = $customer->addresses()->findOrFail($request->integer('address_id'));

        DB::transaction(function () use ($actor, $customer, $address) {
            $wasDefault = (bool) $address->is_default;
            $address->delete();
            if ($wasDefault) {
                $replacement = $customer->addresses()->oldest('id')->first();
                if ($replacement) {
                    $replacement->update(['is_default' => true, 'updated_by' => $actor->getKey()]);
                    $this->syncProfileAddress($actor, $replacement);
                } else {
                    $actor->forceFill(['address' => null])->save();
                }
            }
        });

        return response()->json(['message' => 'success']);
    }

    /** @return array{0: User, 1: Customer} */
    private function customer(
        Request $request,
        StoreCustomerOnboardingService $onboarding,
        StoreIdentityService $identity,
    ): array {
        $storeUser = $this->storeUserFromRequest($request);
        $actor = $storeUser ? User::query()->find($storeUser->getKey()) : null;
        if (! $actor || $actor->is_blocked) {
            abort(401, 'Unauthenticated.');
        }

        $onboarding->ensureCustomerAccount($actor);
        $link = $identity->activeLink($actor, 'customer');

        return [$actor, Customer::query()->findOrFail($link->customer_id)];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $update = false): array
    {
        $sometimes = $update ? 'sometimes|' : '';
        $shippingRequired = 'required|';

        return $request->validate([
            'address_id' => ($update ? 'required|' : 'nullable|').'integer|min:1',
            'label' => $sometimes.'required|string|max:100',
            'street_address' => $sometimes.'required|string|max:500',
            'phone' => 'nullable|string|max:50',
            'city_id' => 'nullable|integer|exists:cities,id',
            'shiply_city_id' => $shippingRequired.'integer|min:1',
            'shiply_village_id' => $shippingRequired.'integer|min:1',
            'shiply_city_name' => $shippingRequired.'string|max:255',
            'shiply_village_name' => $shippingRequired.'string|max:255',
            'delivery_notes' => 'nullable|string|max:2000',
            'is_default' => 'nullable|boolean',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(PartnerAddress $address): array
    {
        return [
            'id' => (int) $address->getKey(),
            'label' => (string) $address->label,
            'street_address' => (string) $address->street_address,
            'phone' => $address->phone,
            'city_id' => $address->city_id ? (int) $address->city_id : null,
            'shiply_city_id' => $address->shiply_city_id ? (int) $address->shiply_city_id : null,
            'shiply_village_id' => $address->shiply_village_id ? (int) $address->shiply_village_id : null,
            'shiply_city_name' => $address->shiply_city_name,
            'shiply_village_name' => $address->shiply_village_name,
            'delivery_notes' => $address->delivery_notes,
            'is_default' => (bool) $address->is_default,
        ];
    }

    private function syncProfileAddress(User $actor, PartnerAddress $address): void
    {
        $actor->forceFill([
            'address' => $address->street_address,
            'city' => $address->city_id ?? $actor->city,
        ])->save();
    }
}
