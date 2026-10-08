<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\OnlineStore\OnlineStorePromotion;
use App\Models\User;
use App\Policies\OnlineStore\OnlineStorePolicy;
use App\Services\OnlineStore\PromotionService;
use App\Services\OnlineStore\StoreIdentityService;
use App\Services\OnlineStore\StorePricingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    private const PERMISSION = 'Online Store Promotions Manage';

    public function index(Request $request)
    {
        $this->authorizeRequest($request);

        return OnlineStorePromotion::query()
            ->with('targets')
            ->withCount('redemptions')
            ->withSum('redemptions as discount_total', 'discount_amount')
            ->orderByDesc('id')
            ->paginate();
    }

    public function show(Request $request, OnlineStorePromotion $promotion)
    {
        $this->authorizeRequest($request, true);

        return ['data' => $promotion->load('targets')];
    }

    public function store(Request $request, PromotionService $service)
    {
        $this->authorizeRequest($request);

        return response()->json(['data' => $service->save($request->user(), $this->validated($request))], 201);
    }

    public function update(Request $request, OnlineStorePromotion $promotion, PromotionService $service)
    {
        $this->authorizeRequest($request, true);

        return ['data' => $service->save($request->user(), $this->validated($request), $promotion)];
    }

    public function activate(Request $request, OnlineStorePromotion $promotion, PromotionService $service)
    {
        $this->authorizeRequest($request, true);

        return ['data' => $service->setActive($request->user(), $promotion, true)];
    }

    public function deactivate(Request $request, OnlineStorePromotion $promotion, PromotionService $service)
    {
        $this->authorizeRequest($request, true);

        return ['data' => $service->setActive($request->user(), $promotion, false)];
    }

    public function destroy(Request $request, OnlineStorePromotion $promotion, PromotionService $service)
    {
        $this->authorizeRequest($request, true);
        $service->setActive($request->user(), $promotion, false);

        return response()->noContent();
    }

    public function preview(Request $request, StoreIdentityService $identity, StorePricingService $pricing)
    {
        $this->authorizeRequest($request);
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id', 'account_role' => 'required|in:customer,seller', 'coupon_code' => 'nullable|string', 'items' => 'required|array|min:1', 'items.*.listing_id' => 'required|integer|exists:online_store_listings,id', 'items.*.size_id' => 'nullable|integer', 'items.*.size_color_id' => 'nullable|integer', 'items.*.quantity' => 'required|integer|min:1']);
        $user = User::findOrFail($data['user_id']);
        $link = $identity->activeLink($user, $data['account_role']);
        $lines = collect($data['items'])->map(fn ($item) => ['listing' => OnlineStoreListing::with('product')->findOrFail($item['listing_id']), 'quantity' => $item['quantity'], 'size_id' => $item['size_id'] ?? null, 'size_color_id' => $item['size_color_id'] ?? null])->all();
        $result = $pricing->price($user, $link, $lines, $data['coupon_code'] ?? null);
        unset($result['coupon']);

        return ['data' => $result];
    }

    public function redemptions(Request $request, OnlineStorePromotion $promotion)
    {
        $this->authorizeRequest($request, true);

        $base = $promotion->redemptions();
        $paginator = (clone $base)
            ->with([
                'user:id,name,email,profile_image_path',
                'customer',
                'seller',
                'salesOrder:id,serial_number,total,status',
            ])
            ->orderByDesc('used_at')
            ->orderByDesc('id')
            ->paginate();

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => [
                'uses' => (clone $base)->count(),
                'unique_users' => (clone $base)->distinct()->count('user_id'),
                'orders' => (clone $base)->distinct()->count('sales_order_id'),
                'items_count' => (int) (clone $base)->sum('items_count'),
                'quantity' => (int) (clone $base)->sum('quantity'),
                'discount_total' => round((float) (clone $base)->sum('discount_amount'), 2),
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
        return $request->validate(['name' => 'sometimes|required|string|max:255', 'description_translations' => 'nullable|array', 'discount_type' => ['sometimes', 'required', Rule::in(['percentage', 'fixed'])], 'discount_value' => 'sometimes|required|numeric|gt:0', 'applies_to' => ['sometimes', 'required', Rule::in(['retail', 'wholesale', 'both'])], 'scope' => ['sometimes', 'required', Rule::in(['global', 'targeted'])], 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date', 'is_active' => 'sometimes|boolean', 'priority' => 'sometimes|integer|min:0', 'targets' => 'sometimes|array', 'targets.*.target_type' => ['required', Rule::in(['listing', 'category'])], 'targets.*.target_id' => 'required|integer|min:1']);
    }
}
