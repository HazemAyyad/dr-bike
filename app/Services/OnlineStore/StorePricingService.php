<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\User;
use Carbon\CarbonInterface;

final class StorePricingService
{
    public function __construct(private StorePriceResolver $prices, private PromotionService $promotions, private CouponService $coupons) {}

    /** @param list<array{listing: OnlineStoreListing, quantity:int, size_color_id:?int}> $lines */
    public function price(User $user, OnlineStoreAccountLink $link, array $lines, ?string $couponCode = null, ?CarbonInterface $at = null, bool $lockCoupon = false): array
    {
        $at ??= now();
        $context = $link->role === 'seller' ? 'wholesale' : 'retail';
        $priced = [];
        $subtotal = 0.0;
        $promotionDiscount = 0.0;
        foreach ($lines as $line) {
            $listing = $line['listing'];
            $resolved = $this->prices->resolve($listing->product);
            $base = $resolved[$context];
            if ($line['size_color_id'] !== null) {
                $variant = collect($resolved['variants'])->firstWhere('id', $line['size_color_id']);
                $base = $variant[$context] ?? null;
            }
            if ($base === null || $base <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['items' => ['An item has no eligible authoritative price.']]);
            }
            $promotion = $this->promotions->winner($listing, $context, $at, $lockCoupon);
            $perUnitPromotion = $promotion ? $this->promotions->discount((float) $base, $promotion) : 0.0;
            $unit = max(0, round((float) $base - $perUnitPromotion, 2));
            $qty = (int) $line['quantity'];
            $lineTotal = round($unit * $qty, 2);
            $categoryIds = \Illuminate\Support\Facades\DB::table('online_store_category_listing as membership')
                ->join('online_store_categories as category', 'category.id', '=', 'membership.online_store_category_id')
                ->where('membership.online_store_listing_id', $listing->getKey())->where('category.is_active', true)
                ->pluck('membership.online_store_category_id')->map(fn ($id) => (int) $id)->all();
            $priced[] = ['listing_id' => (int) $listing->getKey(), 'product_id' => (int) $listing->product_id, 'size_id' => $line['size_id'] ?? null, 'size_color_id' => $line['size_color_id'], 'quantity' => $qty, 'base_unit_price' => round((float) $base, 2), 'unit_price' => $unit, 'line_total' => $lineTotal, 'promotion_id' => $promotion?->getKey(), 'promotion_discount' => round($perUnitPromotion * $qty, 2), 'category_ids' => $categoryIds];
            $subtotal += $lineTotal;
            $promotionDiscount += $perUnitPromotion * $qty;
        }
        $coupon = null;
        $couponDiscount = 0.0;
        if (trim((string) $couponCode) !== '') {
            $coupon = $this->coupons->findEligible((string) $couponCode, $user, $link, $priced, round($subtotal, 2), $context, $at, $lockCoupon);
            $couponDiscount = $this->coupons->amount($coupon, $this->coupons->eligibleSubtotal($coupon, $priced));
        }

        return ['price_context' => $context, 'items' => $priced, 'subtotal' => round($subtotal, 2), 'promotion_discount' => round($promotionDiscount, 2), 'coupon' => $coupon, 'coupon_discount' => $couponDiscount, 'payable_before_delivery' => max(0, round($subtotal - $couponDiscount, 2))];
    }

    public function displayPrices(OnlineStoreListing $listing, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $base = $this->prices->resolve($listing->product);
        $result = ['retail' => null, 'wholesale' => null, 'variants' => []];
        $winners = [];
        foreach (['retail', 'wholesale'] as $context) {
            $promotion = $this->promotions->winner($listing, $context, $at);
            $winners[$context] = $promotion;
            if ($base[$context] === null) {
                continue;
            }
            $discount = $promotion ? $this->promotions->discount((float) $base[$context], $promotion) : 0.0;
            $result[$context] = ['base' => (float) $base[$context], 'promotion_id' => $promotion?->getKey(), 'discount' => $discount, 'final' => max(0, round((float) $base[$context] - $discount, 2))];
        }
        foreach ($base['variants'] as $variant) {
            $row = ['id' => $variant['id'], 'size_id' => $variant['size_id']];
            foreach (['retail', 'wholesale'] as $context) {
                $promotion = $winners[$context] ?? null;
                $value = $variant[$context];
                $discount = ($value !== null && $promotion) ? $this->promotions->discount((float) $value, $promotion) : 0.0;
                $row[$context] = $value === null ? null : ['base' => (float) $value, 'promotion_id' => $promotion?->getKey(), 'discount' => $discount, 'final' => max(0, round((float) $value - $discount, 2))];
            }
            $result['variants'][] = $row;
        }

        return $result;
    }
}
