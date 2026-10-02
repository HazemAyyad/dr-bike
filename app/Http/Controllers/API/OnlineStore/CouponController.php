<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\OnlineStore\OnlineStoreCoupon;
use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Services\OnlineStore\CouponService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    private const PERMISSION = 'Online Store Promotions Manage';

    public function index(Request $request)
    {
        $this->authorizeRequest($request);

        return OnlineStoreCoupon::with(['targets', 'redemptions'])->orderByDesc('id')->paginate();
    }

    public function show(Request $request, OnlineStoreCoupon $coupon)
    {
        $this->authorizeRequest($request, true);

        return ['data' => $coupon->load(['targets', 'redemptions'])];
    }

    public function store(Request $request, CouponService $service)
    {
        $this->authorizeRequest($request);

        return response()->json(['data' => $service->save($request->user(), $this->validated($request))], 201);
    }

    public function update(Request $request, OnlineStoreCoupon $coupon, CouponService $service)
    {
        $this->authorizeRequest($request, true);

        return ['data' => $service->save($request->user(), $this->validated($request), $coupon)];
    }

    public function activate(Request $request, OnlineStoreCoupon $coupon, CouponService $service)
    {
        $this->authorizeRequest($request, true);

        return ['data' => $service->setActive($request->user(), $coupon, true)];
    }

    public function deactivate(Request $request, OnlineStoreCoupon $coupon, CouponService $service)
    {
        $this->authorizeRequest($request, true);

        return ['data' => $service->setActive($request->user(), $coupon, false)];
    }

    public function destroy(Request $request, OnlineStoreCoupon $coupon, CouponService $service)
    {
        $this->authorizeRequest($request, true);
        $service->setActive($request->user(), $coupon, false);

        return response()->noContent();
    }

    public function redemptions(Request $request, OnlineStoreCoupon $coupon)
    {
        $this->authorizeRequest($request, true);

        return $coupon->redemptions()->with(['user', 'customer', 'seller', 'salesOrder'])->orderByDesc('id')->paginate();
    }

    private function authorizeRequest(Request $request, bool $resource = false): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        ($resource ? app(OnlineStorePolicy::class)->authorizeResource($user, self::PERMISSION, true) : app(OnlineStorePolicy::class)->authorize($user, self::PERMISSION))->authorize();
    }

    private function validated(Request $request): array
    {
        return $request->validate(['code' => 'sometimes|required|string|max:100', 'discount_type' => ['sometimes', 'required', Rule::in(['percentage', 'fixed'])], 'discount_value' => 'sometimes|required|numeric|gt:0', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date', 'minimum_order' => 'sometimes|numeric|min:0', 'total_usage_limit' => 'nullable|integer|min:1', 'per_user_usage_limit' => 'nullable|integer|min:1', 'eligible_account_type' => ['sometimes', 'required', Rule::in(['customer', 'seller', 'both'])], 'applies_to' => ['sometimes', 'required', Rule::in(['retail', 'wholesale', 'both'])], 'is_active' => 'sometimes|boolean', 'scope' => ['sometimes', 'required', Rule::in(['global', 'targeted'])], 'targets' => 'sometimes|array', 'targets.*.target_type' => ['required', Rule::in(['listing', 'category'])], 'targets.*.target_id' => 'required|integer|min:1']);
    }
}
