<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\OnlineStore\OnlineStoreCoupon;
use App\Models\OnlineStore\OnlineStoreCouponRedemption;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\OnlineStore\OnlineStoreTargetRegistry;
use App\Support\OnlineStore\OnlineStoreValues;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CouponService
{
    public function save(User $actor, array $data, ?OnlineStoreCoupon $coupon = null): OnlineStoreCoupon
    {
        return DB::transaction(function () use ($actor, $data, $coupon) {
            $coupon ??= new OnlineStoreCoupon;
            $before = $coupon->exists ? $coupon->getAttributes() : null;
            $coupon->fill($data);
            $this->validateDefinition($coupon, $data['targets'] ?? null);
            $duplicateCode = OnlineStoreCoupon::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim((string) $coupon->code))]);
            if ($coupon->exists) {
                $duplicateCode->where($coupon->getKeyName(), '<>', $coupon->getKey());
            }
            if ($duplicateCode->exists()) {
                throw ValidationException::withMessages(['code' => ['Coupon codes must be unique without regard to case.']]);
            }
            $coupon->created_by ??= $actor->getKey();
            $coupon->updated_by = $actor->getKey();
            $coupon->save();
            if (array_key_exists('targets', $data)) {
                $coupon->targets()->delete();
                foreach ($this->normalizedTargets($data['targets']) as $target) {
                    $coupon->targets()->create($target);
                }
            }
            $this->assertScopeTargets($coupon->fresh('targets'), (bool) $coupon->is_active);
            $this->audit($actor, $coupon, $before, $coupon->fresh('targets')->toArray());

            return $coupon->fresh(['targets', 'redemptions']);
        });
    }

    public function setActive(User $actor, OnlineStoreCoupon $coupon, bool $active): OnlineStoreCoupon
    {
        if ($active) {
            $this->assertScopeTargets($coupon->load('targets'), true);
        }

        return $this->save($actor, ['is_active' => $active], $coupon);
    }

    public function findEligible(string $code, User $user, OnlineStoreAccountLink $link, array $items, float $subtotal, string $priceContext, ?CarbonInterface $at = null, bool $lock = false, ?int $excludeRedemptionId = null): OnlineStoreCoupon
    {
        $query = OnlineStoreCoupon::query()->with('targets')->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($code))]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $coupon = $query->first();
        $at ??= now();
        if (! $coupon || ! $coupon->is_active || ($coupon->starts_at && $coupon->starts_at->gt($at)) || ($coupon->ends_at && $coupon->ends_at->lt($at))) {
            throw ValidationException::withMessages(['coupon_code' => ['The coupon is inactive or outside its valid window.']]);
        }
        if ((float) $coupon->minimum_order > $subtotal || ! in_array($coupon->applies_to, [$priceContext, 'both'], true)
            || ! in_array($coupon->eligible_account_type, [$link->role, 'both'], true)) {
            throw ValidationException::withMessages(['coupon_code' => ['The coupon is not eligible for this order.']]);
        }
        if ($coupon->scope === 'targeted' && ! collect($items)->contains(fn ($item) => $coupon->targets->contains(fn ($target) => ($target->target_type === 'listing' && (int) $target->target_id === (int) $item['listing_id'])
            || ($target->target_type === 'category' && in_array((int) $target->target_id, $item['category_ids'] ?? [], true))))) {
            throw ValidationException::withMessages(['coupon_code' => ['The coupon does not target an item in this order.']]);
        }
        if ($coupon->scope === 'global' && $coupon->targets->isNotEmpty()) {
            throw ValidationException::withMessages(['coupon_code' => ['The coupon scope is misconfigured.']]);
        }
        $countable = ['reserved', 'applied'];
        $usage = $coupon->redemptions()->whereIn('status', $countable);
        $userUsage = $coupon->redemptions()->where('user_id', $user->getKey())->whereIn('status', $countable);
        if ($excludeRedemptionId !== null) {
            $usage->where('id', '<>', $excludeRedemptionId);
            $userUsage->where('id', '<>', $excludeRedemptionId);
        }
        if ($coupon->total_usage_limit !== null && $usage->count() >= $coupon->total_usage_limit) {
            throw ValidationException::withMessages(['coupon_code' => ['The coupon usage limit has been reached.']]);
        }
        if ($coupon->per_user_usage_limit !== null && $userUsage->count() >= $coupon->per_user_usage_limit) {
            throw ValidationException::withMessages(['coupon_code' => ['The coupon user limit has been reached.']]);
        }

        return $coupon;
    }

    public function amount(OnlineStoreCoupon $coupon, float $subtotal): float
    {
        $value = $coupon->discount_type === 'percentage' ? $subtotal * ((float) $coupon->discount_value / 100) : (float) $coupon->discount_value;

        return min($subtotal, max(0, round($value, 2)));
    }

    public function eligibleSubtotal(OnlineStoreCoupon $coupon, array $items): float
    {
        if ($coupon->scope === 'global') {
            return round((float) collect($items)->sum('line_total'), 2);
        }

        return round((float) collect($items)->filter(fn ($item) => $coupon->targets->contains(fn ($target) => ($target->target_type === 'listing' && (int) $target->target_id === (int) $item['listing_id'])
            || ($target->target_type === 'category' && in_array((int) $target->target_id, $item['category_ids'] ?? [], true))))->sum('line_total'), 2);
    }

    public function reserve(OnlineStoreCoupon $coupon, SalesOrder $order, User $user, OnlineStoreAccountLink $link, float $amount): OnlineStoreCouponRedemption
    {
        return OnlineStoreCouponRedemption::query()->firstOrCreate(['sales_order_id' => $order->getKey()], [
            'coupon_id' => $coupon->getKey(), 'user_id' => $user->getKey(),
            'customer_id' => $link->role === 'customer' ? $link->customer_id : null,
            'seller_id' => $link->role === 'seller' ? $link->seller_id : null,
            'discount_amount' => $amount, 'status' => 'reserved',
        ]);
    }

    public function markApplied(SalesOrder $order): void
    {
        $redemption = OnlineStoreCouponRedemption::query()->with('coupon.targets')->where('sales_order_id', $order->getKey())->lockForUpdate()->first();
        if (! $redemption || $redemption->status === 'applied') {
            return;
        }
        if ($redemption->status !== 'reserved') {
            throw ValidationException::withMessages(['coupon_code' => ['The coupon reservation is no longer valid.']]);
        }
        $role = $redemption->customer_id ? 'customer' : 'seller';
        $link = OnlineStoreAccountLink::query()->where('user_id', $redemption->user_id)->where('role', $role)
            ->where($role === 'customer' ? 'customer_id' : 'seller_id', $redemption->{$role.'_id'})
            ->where('status', 'active')->whereNotNull('verified_at')->first();
        if (! $link) {
            throw ValidationException::withMessages(['coupon_code' => ['The coupon account is no longer eligible.']]);
        }
        $items = $order->items()->get()->map(function ($item) {
            $listingId = DB::table('online_store_listings')->where('product_id', $item->product_id)->value('id');
            $categoryIds = $listingId ? DB::table('online_store_category_listing as membership')
                ->join('online_store_categories as category', 'category.id', '=', 'membership.online_store_category_id')
                ->where('membership.online_store_listing_id', $listingId)->where('category.is_active', true)
                ->pluck('membership.online_store_category_id')->map(fn ($id) => (int) $id)->all() : [];

            return ['listing_id' => (int) $listingId, 'category_ids' => $categoryIds, 'line_total' => (float) $item->line_total];
        })->all();
        $context = $role === 'seller' ? 'wholesale' : 'retail';
        $this->findEligible($redemption->coupon->code, $redemption->user, $link, $items, (float) $order->subtotal, $context, now(), true, $redemption->getKey());
        $redemption->update(['status' => 'applied', 'applied_at' => now(), 'released_at' => null]);
    }

    public function release(SalesOrder $order): void
    {
        OnlineStoreCouponRedemption::query()->where('sales_order_id', $order->getKey())->whereIn('status', ['reserved', 'applied'])
            ->update(['status' => 'released', 'released_at' => now()]);
    }

    private function validateDefinition(OnlineStoreCoupon $coupon, ?array $targets): void
    {
        if (trim((string) $coupon->code) === '') {
            throw ValidationException::withMessages(['code' => ['A coupon code is required.']]);
        }
        if (! in_array($coupon->discount_type, OnlineStoreValues::DISCOUNT_TYPES, true) || (float) $coupon->discount_value <= 0
            || ($coupon->discount_type === 'percentage' && (float) $coupon->discount_value > 100)) {
            throw ValidationException::withMessages(['discount_value' => ['Discount must be positive and percentage discounts cannot exceed 100.']]);
        }
        if (! in_array($coupon->scope, OnlineStoreValues::DISCOUNT_SCOPES, true)
            || ! in_array($coupon->applies_to, OnlineStoreValues::PRICE_CONTEXTS, true)
            || ! in_array($coupon->eligible_account_type, OnlineStoreValues::ACCOUNT_TYPES, true)) {
            throw ValidationException::withMessages(['scope' => ['Coupon scope or applicability is invalid.']]);
        }
        if ($coupon->starts_at && $coupon->ends_at && $coupon->ends_at->lte($coupon->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => ['The end time must be after the start time.']]);
        }
        if ($targets !== null) {
            $this->normalizedTargets($targets);
        }
    }

    private function assertScopeTargets(OnlineStoreCoupon $coupon, bool $active): void
    {
        if ($coupon->scope === 'global' && $coupon->targets->isNotEmpty()) {
            throw ValidationException::withMessages(['targets' => ['Global coupons cannot have target rows.']]);
        }
        if ($active && $coupon->scope === 'targeted' && $coupon->targets->isEmpty()) {
            throw ValidationException::withMessages(['targets' => ['A targeted coupon requires at least one valid target.']]);
        }
    }

    private function normalizedTargets(array $targets): array
    {
        return collect($targets)->map(function ($target) {
            $type = (string) ($target['target_type'] ?? '');
            $id = (int) ($target['target_id'] ?? 0);
            if (! in_array($type, OnlineStoreTargetRegistry::allowedTargetTypes(OnlineStoreTargetRegistry::CONTEXT_COUPON), true) || $id < 1
                || ! DB::table(OnlineStoreTargetRegistry::targetTable($type))->where('id', $id)->exists()) {
                throw ValidationException::withMessages(['targets' => ['Every coupon target must use an allowed type and existing target.']]);
            }

            return ['target_type' => $type, 'target_id' => $id];
        })->unique(fn ($target) => $target['target_type'].':'.$target['target_id'])->values()->all();
    }

    private function audit(User $actor, OnlineStoreCoupon $coupon, ?array $before, array $after): void
    {
        $action = $before === null ? 'created'
            : ((! (bool) ($before['is_active'] ?? false) && $coupon->is_active) ? 'activated'
                : (((bool) ($before['is_active'] ?? false) && ! $coupon->is_active) ? 'deactivated' : 'updated'));
        DB::table('online_store_audit_events')->insert(['actor_user_id' => $actor->getKey(), 'action' => $action, 'entity_type' => 'coupon', 'entity_id' => $coupon->getKey(), 'before_values' => $before ? json_encode($before) : null, 'after_values' => json_encode($after), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
