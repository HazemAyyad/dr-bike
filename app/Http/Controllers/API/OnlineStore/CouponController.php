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

        return OnlineStoreCoupon::query()
            ->with('targets')
            ->withCount([
                'redemptions',
                'redemptions as active_redemptions_count' => fn ($query) => $query->whereIn('status', ['reserved', 'applied']),
                'redemptions as applied_redemptions_count' => fn ($query) => $query->where('status', 'applied'),
            ])
            ->withSum([
                'redemptions as discount_total' => fn ($query) => $query->whereIn('status', ['reserved', 'applied']),
            ], 'discount_amount')
            ->orderByDesc('id')
            ->paginate();
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

        $base = $coupon->redemptions();
        $paginator = (clone $base)
            ->with([
                'user:id,name,email',
                'customer',
                'seller',
                'salesOrder:id,serial_number,total,status',
            ])
            ->orderByDesc('id')
            ->paginate();
        $countable = (clone $base)->whereIn('status', ['reserved', 'applied']);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'total_records' => (clone $base)->count(),
                'active_uses' => (clone $countable)->count(),
                'applied_uses' => (clone $base)->where('status', 'applied')->count(),
                'reserved_uses' => (clone $base)->where('status', 'reserved')->count(),
                'released_uses' => (clone $base)->where('status', 'released')->count(),
                'unique_users' => (clone $countable)->distinct()->count('user_id'),
                'discount_total' => round((float) (clone $countable)->sum('discount_amount'), 2),
                'total_usage_limit' => $coupon->total_usage_limit,
                'remaining_uses' => $coupon->total_usage_limit === null
                    ? null
                    : max(0, $coupon->total_usage_limit - (clone $countable)->count()),
            ],
        ]);
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
