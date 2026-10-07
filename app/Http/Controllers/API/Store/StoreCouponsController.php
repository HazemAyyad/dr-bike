<?php

namespace App\Http\Controllers\API\Store;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\User;
use App\Services\OnlineStore\StorefrontCatalogService;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\OnlineStore\StorePricingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StoreCouponsController extends StoreBaseController
{
    public function validateCoupon(
        Request $request,
        StoreIdentityService $identity,
        StorefrontCatalogService $catalog,
        StorePricingService $pricing,
    ) {
        $actor = $this->authenticatedUser($request);
        $data = $request->validate([
            'coupon_code' => 'required|string|max:100',
            'account_role' => 'required|in:customer,seller',
            'items' => 'required|array|min:1',
            'items.*.listing_id' => 'required|integer|min:1',
            'items.*.size_id' => 'nullable|integer|min:1',
            'items.*.size_color_id' => 'nullable|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $link = $identity->activeLink($actor, $data['account_role']);
        $lines = collect($data['items'])->map(function (array $item) use ($catalog) {
            $listing = OnlineStoreListing::query()->with('product')->find($item['listing_id']);
            if (! $listing || ! $catalog->isEligible($listing)) {
                throw ValidationException::withMessages([
                    'items' => ['A submitted product is not currently available in the Online Store.'],
                ]);
            }

            return [
                'listing' => $listing,
                'quantity' => (int) $item['quantity'],
                'size_id' => $item['size_id'] ?? null,
                'size_color_id' => $item['size_color_id'] ?? null,
            ];
        })->all();

        $result = $pricing->price($actor, $link, $lines, $data['coupon_code']);
        $coupon = $result['coupon'];

        return response()->json(['data' => [
            'coupon' => [
                'id' => (int) $coupon->getKey(),
                'code' => (string) $coupon->code,
                'discount_type' => (string) $coupon->discount_type,
                'discount_value' => (float) $coupon->discount_value,
                'is_active' => true,
            ],
            'subtotal' => (float) $result['subtotal'],
            'promotion_discount' => (float) $result['promotion_discount'],
            'coupon_discount' => (float) $result['coupon_discount'],
            'payable_before_delivery' => (float) $result['payable_before_delivery'],
        ]]);
    }

    private function authenticatedUser(Request $request): User
    {
        $storeUser = $this->storeUserFromRequest($request);
        if (! $storeUser) {
            abort(401, 'Unauthenticated.');
        }

        $user = User::query()->find($storeUser->getKey());
        if (! $user || $user->is_blocked) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }
}
