<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\OnlineStore\OnlineStorePromotion;
use App\Models\User;
use App\Support\OnlineStore\OnlineStoreTargetRegistry;
use App\Support\OnlineStore\OnlineStoreValues;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PromotionService
{
    public function __construct(private readonly OnlineStoreAuditService $auditService) {}

    public function save(User $actor, array $data, ?OnlineStorePromotion $promotion = null): OnlineStorePromotion
    {
        return DB::transaction(function () use ($actor, $data, $promotion) {
            $promotion ??= new OnlineStorePromotion;
            $before = $promotion->exists ? $promotion->getAttributes() : null;
            $promotion->fill($data);
            $this->validateDefinition($promotion, $data['targets'] ?? null, activating: (bool) ($data['is_active'] ?? $promotion->is_active));
            $promotion->created_by ??= $actor->getKey();
            $promotion->updated_by = $actor->getKey();
            $promotion->save();
            if (array_key_exists('targets', $data)) {
                $promotion->targets()->delete();
                foreach ($this->normalizedTargets($data['targets']) as $target) {
                    $promotion->targets()->create($target);
                }
            }
            $this->assertScopeTargets($promotion->fresh('targets'), (bool) $promotion->is_active);
            $this->audit($actor, $promotion, $before, $promotion->fresh('targets')->toArray());

            return $promotion->fresh('targets');
        });
    }

    public function setActive(User $actor, OnlineStorePromotion $promotion, bool $active): OnlineStorePromotion
    {
        if ($active) {
            $this->assertScopeTargets($promotion->load('targets'), true);
        }

        return $this->save($actor, ['is_active' => $active], $promotion);
    }

    public function winner(OnlineStoreListing $listing, string $priceContext, ?CarbonInterface $at = null, bool $lock = false): ?OnlineStorePromotion
    {
        $at ??= now();
        $categoryIds = DB::table('online_store_category_listing as membership')
            ->join('online_store_categories as category', 'category.id', '=', 'membership.online_store_category_id')
            ->where('membership.online_store_listing_id', $listing->getKey())->where('category.is_active', true)
            ->pluck('membership.online_store_category_id')->map(fn ($id) => (int) $id)->all();

        $query = OnlineStorePromotion::query()->with('targets')->where('is_active', true)
            ->whereIn('applies_to', [$priceContext, 'both'])
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
            ->orderByDesc('priority')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->first(function (OnlineStorePromotion $promotion) use ($listing, $categoryIds) {
            if ($promotion->scope === 'global') {
                return $promotion->targets->isEmpty();
            }

            return $promotion->targets->contains(fn ($target) => ($target->target_type === 'listing' && (int) $target->target_id === (int) $listing->getKey())
                || ($target->target_type === 'category' && in_array((int) $target->target_id, $categoryIds, true))
            );
        });
    }

    public function discount(float $amount, OnlineStorePromotion $promotion): float
    {
        $discount = $promotion->discount_type === 'percentage'
            ? $amount * ((float) $promotion->discount_value / 100)
            : (float) $promotion->discount_value;

        return min($amount, max(0, round($discount, 2)));
    }

    private function validateDefinition(OnlineStorePromotion $promotion, ?array $targets, bool $activating): void
    {
        if (trim((string) $promotion->name) === '') {
            throw ValidationException::withMessages(['name' => ['A promotion name is required.']]);
        }
        if (! in_array($promotion->discount_type, OnlineStoreValues::DISCOUNT_TYPES, true) || (float) $promotion->discount_value <= 0
            || ($promotion->discount_type === 'percentage' && (float) $promotion->discount_value > 100)) {
            throw ValidationException::withMessages(['discount_value' => ['Discount must be positive and percentage discounts cannot exceed 100.']]);
        }
        if (! in_array($promotion->scope, OnlineStoreValues::DISCOUNT_SCOPES, true)) {
            throw ValidationException::withMessages(['scope' => ['Promotion scope is invalid.']]);
        }
        if (! in_array($promotion->applies_to, OnlineStoreValues::PRICE_CONTEXTS, true)) {
            throw ValidationException::withMessages(['applies_to' => ['Price applicability is invalid.']]);
        }
        if ($promotion->starts_at && $promotion->ends_at && $promotion->ends_at->lte($promotion->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => ['The end time must be after the start time.']]);
        }
        if ($targets !== null) {
            $this->normalizedTargets($targets);
        }
    }

    private function assertScopeTargets(OnlineStorePromotion $promotion, bool $activating): void
    {
        if ($promotion->scope === 'global' && $promotion->targets->isNotEmpty()) {
            throw ValidationException::withMessages(['targets' => ['Global promotions cannot have target rows.']]);
        }
        if ($activating && $promotion->scope === 'targeted' && $promotion->targets->isEmpty()) {
            throw ValidationException::withMessages(['targets' => ['A targeted promotion requires at least one valid target.']]);
        }
    }

    private function normalizedTargets(array $targets): array
    {
        return collect($targets)->map(function ($target) {
            $type = (string) ($target['target_type'] ?? '');
            $id = (int) ($target['target_id'] ?? 0);
            if (! in_array($type, OnlineStoreTargetRegistry::allowedTargetTypes(OnlineStoreTargetRegistry::CONTEXT_PROMOTION), true)
                || $id < 1 || ! DB::table(OnlineStoreTargetRegistry::targetTable($type))->where('id', $id)->exists()) {
                throw ValidationException::withMessages(['targets' => ['Every promotion target must use an allowed type and existing target.']]);
            }

            return ['target_type' => $type, 'target_id' => $id];
        })->unique(fn ($target) => $target['target_type'].':'.$target['target_id'])->values()->all();
    }

    private function audit(User $actor, OnlineStorePromotion $promotion, ?array $before, array $after): void
    {
        $action = $before === null ? 'created'
            : ((! (bool) ($before['is_active'] ?? false) && $promotion->is_active) ? 'activated'
                : (((bool) ($before['is_active'] ?? false) && ! $promotion->is_active) ? 'deactivated' : 'updated'));
        $this->auditService->record($actor, $action, 'promotion', (int) $promotion->getKey(), $before, $after);
    }
}
